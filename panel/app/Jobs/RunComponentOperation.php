<?php

namespace App\Jobs;

use App\Models\ComponentOperation;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use App\Services\Components\SystemPackages;
use App\Services\Security\Waf;
use App\Services\Settings\PanelSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Installs, updates or removes a component through the agent. The agent
 * writes each step to the operation's run log and rolls back a failed
 * install; this records the outcome.
 *
 * Upgrading a database server to a new major version dumps every
 * database first and can run for hours: it goes to the "backups" queue,
 * so it never runs next to a backup or restore.
 */
class RunComponentOperation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public ComponentOperation $operation)
    {
        if ($operation->action === 'upgrade') {
            $this->timeout = 43400;
            $this->onConnection('backups')->onQueue('backups');
        }
        if ($operation->component === SystemPackages::COMPONENT) {
            // apt may first wait for unattended-upgrades to finish.
            $this->timeout = 3700;
        }
    }

    public function handle(AgentClient $agent, Components $components): void
    {
        $op = $this->operation->fresh();
        if ($op === null || $op->status !== 'queued') {
            return;
        }
        $op->update(['status' => 'running']);
        $params = ['id' => $op->component, 'run' => $op->run];
        $database = in_array($op->component, ['mysql', 'mariadb', 'postgresql'], true);
        if ($database) {
            $params += app(PanelSettings::class)->managerSetupParams();
            if (in_array($op->action, ['install', 'upgrade'], true) && $op->detail) {
                $params['version'] = $op->detail;
            }
        }
        if ($op->component === SystemPackages::COMPONENT) {
            $method = 'packages.upgrade';
            $params = ['run' => $op->run, 'packages' => $op->packages ?? []];
        } elseif (str_starts_with($op->action, 'ext-')) {
            $method = 'component.extension';
            $params = ['version' => substr($op->component, 3), 'name' => (string) $op->detail, 'remove' => $op->action === 'ext-remove', 'run' => $op->run];
        } else {
            $method = 'component.'.$op->action;
        }
        try {
            $agent->call($method, $params, match (true) {
                $op->component === SystemPackages::COMPONENT => config('serfex.agent.packages_timeout'),
                $op->action === 'upgrade' => config('serfex.agent.upgrade_timeout'),
                default => config('serfex.agent.component_timeout'),
            });
        } catch (AgentException $e) {
            $op->update(['status' => 'failed', 'error' => $e->reason, 'finished_at' => now()]);
            $components->statuses();
            $components->recorded($op);

            return;
        }
        $op->update(['status' => 'succeeded', 'error' => null, 'finished_at' => now()]);
        $components->statuses();
        $components->recorded($op);
        // Removing ModSecurity took the sites' own WAF settings off the
        // server; a new install brings them back.
        if ($op->component === 'modsecurity' && $op->action === 'install') {
            app(Waf::class)->syncAll();
        }
    }

    /**
     * The job died or ran out of time (a killed worker's job ends here on
     * its retry). The agent may still have finished: its run log decides.
     */
    public function failed(?\Throwable $e): void
    {
        $op = $this->operation->fresh();
        if ($op === null || ! $op->isRunning()) {
            return;
        }
        $reason = $e?->getMessage() ?: __('İşlem yarıda kaldı.');
        if (! app(Components::class)->settle($op, $reason)) {
            $op->update(['status' => 'failed', 'error' => $reason, 'finished_at' => now()]);
        }
    }
}
