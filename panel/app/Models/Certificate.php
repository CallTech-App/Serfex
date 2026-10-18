<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Support\CertificateState;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'kind', 'name', 'source', 'domains', 'challenge', 'issuer', 'serial', 'fingerprint', 'not_before', 'not_after', 'auto_renew', 'status', 'last_error', 'renew_error', 'attempted_at', 'issued_at'])]
class Certificate extends Model
{
    /** @use HasFactory<CertificateFactory> */
    use HasFactory;

    public const LETS_ENCRYPT = 'letsencrypt';

    public const CUSTOM = 'custom';

    /** Days before expiry a certificate shows as expiring. */
    public const WARN_DAYS = 14;

    protected $attributes = [
        'kind' => 'site',
        'domains' => '[]',
        'status' => 'issuing',
        'auto_renew' => true,
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'status' => CertificateStatus::class,
            'auto_renew' => 'boolean',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'attempted_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Sites serving this certificate.
     *
     * @return HasMany<Site, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * Certificates someone should look at: requests that failed or wait for
     * DNS, renewals that failed, and certificates that are about to expire
     * or have expired.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function needsAttention(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereIn('status', [CertificateStatus::Failed, CertificateStatus::WaitingDns])
            ->orWhereNotNull('renew_error')
            ->orWhere('not_after', '<=', now()->addDays(self::WARN_DAYS)));
    }

    /**
     * How many certificates in use (or requested) need attention; shown
     * next to "SSL Sertifikaları" in the menu.
     */
    public static function attentionCount(): int
    {
        return self::query()->needsAttention()
            ->where(fn (Builder $q) => $q->whereIn('kind', ['panel', 'mail'])->orWhereHas('users')->orWhereNull('not_after'))
            ->count();
    }

    public function isLetsEncrypt(): bool
    {
        return $this->source === self::LETS_ENCRYPT;
    }

    public function isWildcard(): bool
    {
        return collect($this->domains)->contains(fn ($d) => str_starts_with($d, '*.'));
    }

    /**
     * True once the files are on the server (they stay during a renewal).
     */
    public function isInstalled(): bool
    {
        return $this->not_after !== null;
    }

    public function isExpired(): bool
    {
        return $this->not_after !== null && $this->not_after->isPast();
    }

    /**
     * Whole days until expiry, negative once expired.
     */
    public function daysLeft(): ?int
    {
        return $this->not_after === null ? null : (int) floor(now()->diffInDays($this->not_after, false));
    }

    public function issuerLabel(): string
    {
        if ($this->issuer === null) {
            return $this->isLetsEncrypt() ? "Let's Encrypt" : 'Kendi sertifikanız';
        }

        return $this->isLetsEncrypt() && str_contains($this->issuer, "Let's Encrypt") ? "Let's Encrypt" : $this->issuer;
    }

    public function state(): CertificateState
    {
        return match (true) {
            $this->status === CertificateStatus::Issuing => new CertificateState($this->isInstalled() ? 'Yenileniyor' : 'Alınıyor', 'info', busy: true),
            $this->isExpired() => new CertificateState('Süresi doldu', 'crit'),
            $this->status === CertificateStatus::WaitingDns => new CertificateState('DNS bekleniyor', 'warn'),
            $this->status === CertificateStatus::Failed => new CertificateState('Alınamadı', 'crit'),
            $this->renew_error !== null => new CertificateState('Yenilenemedi', 'warn'),
            $this->daysLeft() <= self::WARN_DAYS => new CertificateState($this->daysLeft().' gün kaldı', 'warn'),
            default => new CertificateState('Geçerli', 'ok'),
        };
    }

    /**
     * Fill in what the agent reported about the installed certificate.
     *
     * @param  array<string, mixed>  $info
     */
    public function recordInstalled(array $info): void
    {
        $this->update([
            'issuer' => $info['issuer'] ?? null,
            'serial' => $info['serial'] ?? null,
            'fingerprint' => $info['fingerprint'] ?? null,
            'not_before' => $info['not_before'] ?? null,
            'not_after' => $info['not_after'] ?? null,
            'domains' => $info['names'] ?? $this->domains,
            'status' => CertificateStatus::Active,
            'last_error' => null,
            'renew_error' => null,
            'attempted_at' => now(),
            'issued_at' => now(),
        ]);
    }
}
