<?php

namespace App\Services\Monitor;

use App\Models\IpBlock;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the agent's live numbers (monitor.live).
 */
class LiveStats
{
    /** Cache key of the number of things that need a look, for the menu. */
    public const ATTENTION_KEY = 'servik.monitor.attention';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Averages over the last $window seconds; with $history, that site's
     * samples too.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function snapshot(int $window, ?string $history = null): array
    {
        $params = ['window' => $window];
        if ($history !== null) {
            $params['history'] = $history;
        }

        return (array) $this->agent->call('monitor.live', $params, 5);
    }

    /**
     * Suspect addresses that are not blocked yet.
     *
     * @param  array<string, mixed>  $snapshot
     * @return list<array<string, mixed>>
     */
    public static function openSuspects(array $snapshot): array
    {
        $blocked = IpBlock::active()->pluck('address')->all();

        return array_values(array_filter($snapshot['suspects'] ?? [], function ($s) use ($blocked) {
            foreach ($blocked as $b) {
                if (IpBlocks::contains($b, $s['ip'])) {
                    return false;
                }
            }

            return ($s['count'] ?? 0) > 0;
        }));
    }

    /**
     * How many sites have a problem plus how many addresses attack now,
     * remembered for the menu badge.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public static function rememberAttention(array $snapshot): int
    {
        $open = self::openSuspects($snapshot);
        $attacked = array_count_values(array_filter(array_column($open, 'target')));
        // An address that breaks two rules on one site is one attack.
        $count = count(array_unique(array_map(fn ($s) => $s['ip'].'|'.$s['target'], $open)));
        foreach ($snapshot['sites'] ?? [] as $u) {
            [, $rank] = Health::site($u, 0, false);
            if ($rank <= 2 && ! isset($attacked[$u['domain']])) {
                $count++;
            }
        }
        Cache::put(self::ATTENTION_KEY, $count, now()->addMinutes(3));

        return $count;
    }
}
