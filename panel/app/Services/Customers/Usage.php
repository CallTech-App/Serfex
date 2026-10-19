<?php

namespace App\Services\Customers;

use App\Models\Site;
use App\Models\SiteTraffic;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Records what quotas are measured against: the disk each account's home
 * directory takes and the traffic each site sends this month.
 */
class Usage
{
    /** The agent's traffic counters as last seen. */
    private const COUNTERS_KEY = 'servik.traffic.counters';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Measures the home directories of $accounts (all accounts created on
     * the server when null) and stores the result. Returns how many were
     * measured.
     *
     * @param  Collection<int, SystemUser>|null  $accounts
     */
    public function measureDisk(?Collection $accounts = null): int
    {
        $accounts ??= SystemUser::whereNotNull('uid')->get();
        $count = 0;
        foreach ($accounts->filter(fn (SystemUser $a) => $a->uid !== null)->chunk(500) as $chunk) {
            $result = (array) $this->agent->call('usage.disk', ['users' => $chunk->pluck('name')->values()->all()], config('servik.agent.job_timeout'));
            foreach ($chunk as $account) {
                if (isset($result[$account->name])) {
                    $account->update(['disk_bytes' => (int) $result[$account->name]['bytes'], 'disk_measured_at' => now()]);
                    $count++;
                }
            }
        }
        Quotas::forget();

        return $count;
    }

    /**
     * Adds what the sites sent since the last call to this month's
     * traffic. The agent counts bytes per site since it started; a new
     * start time means it restarted and counts from zero again.
     *
     * @param  array<string, mixed>  $snapshot  monitor.live
     */
    public function recordTraffic(array $snapshot): void
    {
        $started = $snapshot['started'] ?? null;
        if ($started === null) {
            return;
        }
        $current = [];
        foreach ($snapshot['sites'] ?? [] as $site) {
            $current[$site['domain']] = (int) ($site['traffic'] ?? 0);
        }
        $previous = Cache::get(self::COUNTERS_KEY);
        Cache::forever(self::COUNTERS_KEY, ['started' => $started, 'sites' => $current]);

        if ($previous === null) {
            // First look: count only what a just-started agent saw, not
            // traffic from before quotas were recorded.
            if (CarbonImmutable::parse($started)->lt(now()->subMinutes(2))) {
                return;
            }
            $previous = ['started' => $started, 'sites' => []];
        }
        $restarted = $previous['started'] !== $started;
        $ids = Site::whereIn('domain', array_keys($current))->pluck('id', 'domain');
        $month = now()->startOfMonth()->toDateString();
        $rows = [];
        foreach ($current as $domain => $bytes) {
            $before = $restarted ? 0 : (int) ($previous['sites'][$domain] ?? 0);
            $delta = $bytes >= $before ? $bytes - $before : $bytes;
            if ($delta > 0 && isset($ids[$domain])) {
                $rows[] = ['site_id' => $ids[$domain], 'month' => $month, 'bytes' => $delta, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        if ($rows !== []) {
            SiteTraffic::upsert($rows, ['site_id', 'month'], [
                'bytes' => DB::raw('site_traffic.bytes + excluded.bytes'),
                'updated_at' => DB::raw('excluded.updated_at'),
            ]);
        }
    }
}
