<?php

namespace App\Services\Cli;

use App\Models\ApiKey;
use App\Models\CliLogin;
use App\Models\User;
use App\Services\Settings\ApiKeys;
use Illuminate\Support\Str;

/**
 * Signing the serfex command line in through the browser:
 *
 *   1. `serfex login` asks for a sign-in (start) and shows its code and a
 *      link to /cli/approve.
 *   2. The person, signed in to the panel, checks the code and approves
 *      it (approve) or refuses it (deny).
 *   3. The command line, asking every few seconds with its own secret,
 *      collects the new key once (collect).
 *
 * The key is a cli API key of that person: it can do what they can do in
 * the panel, read-only if they chose so, and it is listed (and can be
 * closed) under Hesap güvenliği.
 */
class CliLogins
{
    /** How long a sign-in waits for its approval. */
    public const MINUTES = 10;

    /** How often the command line may ask, in seconds. */
    public const INTERVAL = 2;

    /** How long a command line key lasts. */
    public const KEY_DAYS = 365;

    /** No 0/O or 1/I, so a code read aloud is typed right. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly ApiKeys $keys) {}

    /**
     * @return array{0: CliLogin, 1: string} the sign-in and the secret the command line asks with
     */
    public function start(string $client, ?string $ip): array
    {
        CliLogin::where('expires_at', '<', now()->subDay())->delete();
        $device = Str::random(48);
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $code = substr($code, 0, 4).'-'.substr($code, 4);
        } while (CliLogin::where('code', $code)->exists());

        $login = CliLogin::create([
            'code' => $code,
            'device_hash' => hash('sha256', $device),
            'client' => Str::limit(trim($client) ?: 'serfex', 120, ''),
            'ip' => $ip,
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);

        return [$login, $device];
    }

    /**
     * The sign-in a typed code names, in any case and with or without
     * the dash.
     */
    public function find(string $code): ?CliLogin
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($code) !== 8) {
            return null;
        }

        return CliLogin::where('code', substr($code, 0, 4).'-'.substr($code, 4))->first();
    }

    /**
     * Makes the key for $user. A read-only administrator's key is always
     * read-only.
     */
    public function approve(CliLogin $login, User $user, string $scope): ApiKey
    {
        if ($user->isReadOnly()) {
            $scope = 'read';
        }
        [$key, $plain] = $this->keys->create(
            $user,
            Str::limit(__('Komut satırı: :client', ['client' => $login->client]), 80, ''),
            $scope,
            [],
            now()->addDays(self::KEY_DAYS),
            ApiKey::CLI,
            $login->client,
        );
        $login->update(['status' => 'approved', 'user_id' => $user->id, 'api_key_id' => $key->id, 'secret' => $plain]);

        return $key;
    }

    public function deny(CliLogin $login, User $user): void
    {
        $login->update(['status' => 'denied', 'user_id' => $user->id]);
    }

    /**
     * What the command line gets when it asks with $device: pending,
     * approved (with the key, once), denied or expired; null when the
     * secret is unknown.
     *
     * @return array{status: string, key?: string, user?: User}|null
     */
    public function collect(string $device): ?array
    {
        $login = CliLogin::with('user')->where('device_hash', hash('sha256', $device))->first();
        if ($login === null) {
            return null;
        }
        if ($login->status === 'approved' && $login->secret !== null) {
            $plain = $login->secret;
            $login->update(['status' => 'collected', 'secret' => null]);

            return ['status' => 'approved', 'key' => $plain, 'user' => $login->user];
        }

        return match (true) {
            $login->status === 'denied' => ['status' => 'denied'],
            $login->status === 'collected', $login->isExpired() => ['status' => 'expired'],
            default => ['status' => 'pending'],
        };
    }
}
