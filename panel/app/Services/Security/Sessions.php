<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where an account is signed in: the panel keeps sessions in its
 * database, so each browser can be listed and signed out from here.
 */
class Sessions
{
    public function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The account's sessions, this browser first.
     *
     * @return list<array{id: string, ip: ?string, agent: string, last: Carbon, current: bool}>
     */
    public function of(User $user, ?string $current): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)->orderByDesc('last_activity')->limit(50)->get()
            ->map(fn ($s) => [
                'id' => (string) $s->id,
                'ip' => $s->ip_address,
                'agent' => self::describe((string) $s->user_agent),
                'last' => Carbon::createFromTimestamp($s->last_activity),
                'current' => $s->id === $current,
            ])->sortByDesc('current')->values()->all();
    }

    /**
     * Signs one session out; it is gone, there is nothing to undo.
     */
    public function revoke(User $user, string $id): void
    {
        if ($this->available()) {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', $id)->delete();
        }
    }

    /**
     * Signs out every session of the account except $keep.
     */
    public function revokeOthers(User $user, ?string $keep): int
    {
        if (! $this->available()) {
            return 0;
        }

        return DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($keep, fn ($q) => $q->where('id', '!=', $keep))->delete();
    }

    /**
     * "Chrome · Windows" from a user agent.
     */
    public static function describe(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            str_contains($agent, 'curl/') => 'curl',
            default => 'Tarayıcı',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? $browser.' · '.$os : $browser;
    }
}
