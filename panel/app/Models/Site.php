<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\SiteStatus;
use App\Support\CertificateState;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['system_user_id', 'domain', 'aliases', 'php_version', 'document_root', 'web_root', 'certificate_id', 'https_redirect', 'status', 'last_error', 'provisioned_at'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $attributes = [
        'aliases' => '[]',
        'status' => 'provisioning',
        'https_redirect' => true,
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'status' => SiteStatus::class,
            'provisioned_at' => 'datetime',
            'https_redirect' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SystemUser, $this>
     */
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * The certificate the site serves HTTPS with.
     *
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * Every certificate obtained or uploaded for the site, in use or not.
     *
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * The Laravel application on the site, if any.
     *
     * @return HasOne<LaravelApp, $this>
     */
    public function laravelApp(): HasOne
    {
        return $this->hasOne(LaravelApp::class);
    }

    /**
     * Host names the site answers to.
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        return [$this->domain, ...array_values($this->aliases ?? [])];
    }

    /**
     * True when the site is served over HTTPS.
     */
    public function hasHttps(): bool
    {
        return $this->certificate !== null && $this->certificate->isInstalled();
    }

    /**
     * A Let's Encrypt request that has not produced the site's certificate
     * yet: being issued, waiting for DNS or failed.
     */
    public function pendingCertificate(): ?Certificate
    {
        return $this->certificates
            ->where('id', '!=', $this->certificate_id)
            ->where('status', '!=', CertificateStatus::Active)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * The site's HTTPS state for lists: its certificate's, or the pending
     * request's, or "no certificate".
     */
    public function sslState(): CertificateState
    {
        if ($this->certificate !== null) {
            return $this->certificate->state();
        }

        return $this->pendingCertificate()?->state() ?? new CertificateState('Sertifika yok', 'muted');
    }

    /**
     * Parameters for the agent's site.apply method.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        $spec = [
            'domain' => $this->domain,
            'user' => $this->systemUser->name,
            'php_version' => $this->php_version,
            'aliases' => array_values($this->aliases ?? []),
        ];
        if ($this->web_root !== null) {
            $spec['web_root'] = $this->web_root;
        }
        if ($this->hasHttps()) {
            $spec['tls'] = ['certificate' => $this->certificate->name, 'redirect' => $this->https_redirect];
        }

        return $spec;
    }
}
