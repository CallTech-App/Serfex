<?php

namespace App\Services\Settings;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Araçlar ve Ayarlar → API anahtarları: keys for the panel's API
 * (/api/v1), for billing systems, scripts and monitoring.
 *
 * The full key is shown once; only its SHA-256 is kept. A key acts as the
 * full administrator who made it and stops working when that person is
 * removed or is no longer a full administrator.
 */
class ApiKeys
{
    public const PREFIX = 'svk_panel_';

    /** Requests per minute per key. */
    public const PER_MINUTE = 60;

    /** How long a revoked key can be brought back. */
    public const UNDO_MINUTES = 10;

    /**
     * @param  list<string>  $ips  addresses or CIDR ranges; empty for any
     * @return array{0: ApiKey, 1: string} the key and its plain text
     */
    public function create(User $by, string $name, string $scope, array $ips, ?\DateTimeInterface $expires, string $kind = ApiKey::PANEL, ?string $client = null): array
    {
        $plain = self::PREFIX.Str::random(40);
        $key = ApiKey::create([
            'user_id' => $by->id,
            'kind' => $kind,
            'client' => $client,
            'name' => $name,
            'prefix' => substr($plain, 0, strlen(self::PREFIX) + 4),
            'token_hash' => hash('sha256', $plain),
            'scope' => $scope,
            'allowed_ips' => $ips === [] ? null : array_values($ips),
            'expires_at' => $expires,
        ]);

        return [$key, $plain];
    }

    /**
     * The key matching a plain key, usable or not.
     */
    public function find(string $plain): ?ApiKey
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 100) {
            return null;
        }

        return ApiKey::with('user')->where('token_hash', hash('sha256', $plain))->first();
    }

    public function revoke(ApiKey $key): void
    {
        $key->update(['revoked_at' => now()]);
    }

    public function restore(ApiKey $key): bool
    {
        if ($key->revoked_at === null || $key->revoked_at->lt(now()->subMinutes(self::UNDO_MINUTES))) {
            return false;
        }
        $key->update(['revoked_at' => null]);

        return true;
    }
}
