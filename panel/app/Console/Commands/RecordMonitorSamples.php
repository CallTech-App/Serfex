<?php

namespace App\Console\Commands;

use App\Models\MonitorSample;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Customers\Usage;
use App\Services\Monitor\LiveStats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Keeps a per-minute history of what each site used (a day of it), for
 * the "last hour" and "last 24 hours" views of Canlı İzleme, and adds up
 * each site's traffic for its customer's quota.
 */
#[Signature('servik:monitor-record')]
#[Description('Record the last minute of every site\'s resource use')]
class RecordMonitorSamples extends Command
{
    public function handle(LiveStats $stats, Usage $usage): int
    {
        try {
            $snap = $stats->snapshot(60);
        } catch (AgentException $e) {
            $this->warn('Agent unavailable: '.$e->getMessage());

            return self::FAILURE;
        }
        $at = now()->startOfMinute();
        $sites = Site::pluck('id', 'domain');
        $rows = [];
        foreach ($snap['sites'] ?? [] as $u) {
            if (! isset($sites[$u['domain']])) {
                continue;
            }
            $rows[] = [
                'recorded_at' => $at,
                'site_id' => $sites[$u['domain']],
                'cpu' => (float) $u['cpu'],
                'memory' => (int) $u['memory'],
                'io' => (float) $u['io'],
                'requests' => (float) $u['requests'],
                'server_errors' => (float) $u['server_errors'],
                'php_busy' => ($u['php']['known'] ?? false) ? (float) $u['php']['busy'] : null,
                'php_max' => $u['php']['max'] ?? null,
                'db_active' => (float) ($u['db']['active'] ?? 0),
                'mail_per_hour' => (float) $u['mail_per_hour'],
            ];
        }
        $rows[] = [
            'recorded_at' => $at, 'site_id' => null,
            'cpu' => (float) ($snap['server']['cpu'] ?? 0), 'memory' => (int) ($snap['server']['memory'] ?? 0),
            'io' => 0, 'requests' => array_sum(array_column($snap['sites'] ?? [], 'requests')), 'server_errors' => 0,
            'php_busy' => null, 'php_max' => null, 'db_active' => 0, 'mail_per_hour' => 0,
        ];
        MonitorSample::where('recorded_at', $at)->delete();
        MonitorSample::insert($rows);
        MonitorSample::where('recorded_at', '<', now()->subDay())->delete();
        LiveStats::rememberAttention($snap);
        $usage->recordTraffic($snap);

        return self::SUCCESS;
    }
}
