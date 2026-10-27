<?php

namespace App\Services\Monitor;

use App\Models\ResourceLimit;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Temporary caps on a site's CPU, memory and PHP workers, which the agent
 * enforces and ends on time.
 *
 * This is one of the two actions Serfex AI may take without asking, so
 * every caller goes through here; the AI (source "ai") may set limits of
 * at most two hours.
 */
class ResourceLimits
{
    /** The longest limit Serfex AI may set on its own. */
    public const AI_MAX_MINUTES = 120;

    /** The longest limit anyone may set: a week. */
    public const MAX_MINUTES = 7 * 24 * 60;

    /** Durations offered in the panel, in minutes. */
    public const DURATIONS = [15 => '15 dk', 30 => '30 dk', 60 => '1 saat', 360 => '6 saat', 1440 => '24 saat'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Caps a site, replacing the limit it has.
     *
     * @param  int|null  $cpu  percent of one CPU core (100 = one core)
     *
     * @throws MonitorException when the limit cannot be set as asked
     * @throws AgentException
     */
    public function apply(Site $site, ?int $cpu, ?int $memoryMb, ?int $phpWorkers, int $minutes, string $reason = '',
        ?User $by = null, string $source = 'panel'): ResourceLimit
    {
        $cpu = $cpu ?: null;
        $memoryMb = $memoryMb ?: null;
        $phpWorkers = $phpWorkers ?: null;
        if ($cpu === null && $memoryMb === null && $phpWorkers === null) {
            throw new MonitorException(__('CPU, bellek ya da PHP işçisinden en az birini sınırlayın.'));
        }
        if ($cpu !== null && ($cpu < 5 || $cpu > 6400)) {
            throw new MonitorException(__('CPU sınırı bir çekirdeğin %5\'i ile %6400\'ü arasında olmalı.'));
        }
        if ($memoryMb !== null && $memoryMb < 64) {
            throw new MonitorException(__('Bellek sınırı en az 64 MB olmalı.'));
        }
        if ($phpWorkers !== null && ($phpWorkers < 1 || $phpWorkers > 1000)) {
            throw new MonitorException(__('PHP işçisi sayısı 1 ile 1000 arasında olmalı.'));
        }
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw new MonitorException(__('Süre 1 dakika ile 7 gün arasında olmalı.'));
        }
        if ($source === 'ai' && $minutes > self::AI_MAX_MINUTES) {
            throw new MonitorException(__('Serfex AI bir siteyi en fazla 2 saat sınırlayabilir.'));
        }

        $this->agent->call('monitor.limit.apply', [
            'domain' => $site->domain,
            'cpu' => $cpu ?? 0,
            'memory_mb' => $memoryMb ?? 0,
            'php_workers' => $phpWorkers ?? 0,
            'seconds' => $minutes * 60,
        ], config('serfex.agent.job_timeout'));

        $site->resourceLimits()->active()->update(['removed_at' => now()]);

        return $site->resourceLimits()->create([
            'cpu' => $cpu,
            'memory_mb' => $memoryMb,
            'php_workers' => $phpWorkers,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 200) : null,
            'source' => $source,
            'user_id' => $by?->id,
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    /**
     * Ends a limit now.
     *
     * @throws AgentException
     */
    public function lift(ResourceLimit $limit): void
    {
        if ($limit->isActive()) {
            $this->agent->call('monitor.limit.remove', ['domain' => $limit->site->domain], config('serfex.agent.job_timeout'));
        }
        $limit->update(['removed_at' => $limit->removed_at ?? now()]);
    }

    /**
     * Stops the site's PHP-FPM workers, so a pool whose workers hang starts
     * afresh with the next request. Other sites are not touched. Returns
     * how many workers were stopped.
     *
     * @throws AgentException
     */
    public function restartPhp(Site $site): int
    {
        $result = $this->agent->call('php.restart', ['domain' => $site->domain], config('serfex.agent.job_timeout'));

        return (int) ($result['stopped'] ?? 0);
    }
}
