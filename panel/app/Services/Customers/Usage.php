<?php

namespace App\Services\Customers;

use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Collection;

/**
 * Measures the disk each account's home directory, and each site's folder
 * in it, takes. (The traffic quotas count comes from the statistics,
 * StatsCollector.)
 */
class Usage
{
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
                    $measured = (array) $result[$account->name];
                    $account->update(['disk_bytes' => (int) $measured['bytes'], 'disk_measured_at' => now()]);
                    foreach ($account->sites()->get(['id', 'domain']) as $site) {
                        $site->update(['disk_bytes' => (int) ($measured['sites'][$site->domain] ?? 0)]);
                    }
                    $count++;
                }
            }
        }
        Quotas::forget();

        return $count;
    }
}
