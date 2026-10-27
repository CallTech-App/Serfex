<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * A key for the panel's API. The full key is shown once; only its SHA-256
 * is kept. A key acts as the person it belongs to, within its scope: read
 * (GET only) or write.
 *
 * Two kinds: panel keys, made by a full administrator under API
 * anahtarları for billing systems and scripts; and cli keys, made when
 * someone signs the serfex command line in and approves it in the
 * browser. A cli key can do what its person can do in the panel.
 */
#[Fillable(['user_id', 'kind', 'client', 'name', 'prefix', 'token_hash', 'scope', 'allowed_ips', 'last_used_at', 'last_used_ip', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ApiKey extends Model
{
    /** Scopes: key => [name, what the key may do]. */
    public const SCOPES = [
        'read' => ['Salt okunur', 'Yalnızca okur: siteler, müşteriler, paketler, DNS ve sunucu durumu.'],
        'write' => ['Okuma ve yazma', 'Okur, ayrıca site, müşteri ve DNS kaydı oluşturur, değiştirir ve siler.'],
    ];

    public const PANEL = 'panel';

    public const CLI = 'cli';

    protected $attributes = [
        'kind' => self::PANEL,
    ];

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isCli(): bool
    {
        return $this->kind === self::CLI;
    }

    public function scopeName(): string
    {
        return self::SCOPES[$this->scope][0] ?? $this->scope;
    }

    /**
     * Whether $ip may use the key: any address unless the key lists some.
     */
    public function allowsIp(?string $ip): bool
    {
        if ($this->allowed_ips === null || $this->allowed_ips === []) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $this->allowed_ips);
    }
}
