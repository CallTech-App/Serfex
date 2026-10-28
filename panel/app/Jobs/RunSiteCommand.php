<?php

namespace App\Jobs;

use App\Models\SiteCommand;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\CommandLine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs a Composer or Node.js command in a folder of a site as the site's
 * account. The output streams to the command's run log.
 */
class RunSiteCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1950;

    public function __construct(public SiteCommand $command) {}

    public function handle(AgentClient $agent): void
    {
        $command = $this->command->fresh(['site.systemUser']);
        if ($command === null || $command->status !== 'queued') {
            return;
        }
        $command->update(['status' => 'running']);
        try {
            $result = $agent->call('site.tool.run', $command->site->toolTarget() + [
                'path' => $command->path,
                'run' => $command->run,
                'tool' => $command->tool,
                'args' => CommandLine::split($command->command),
            ], config('serfex.agent.deploy_timeout'));
        } catch (AgentException $e) {
            $command->update(['status' => 'failed', 'exit_code' => null, 'error' => $e->reason ?: $e->getMessage(), 'finished_at' => now()]);

            return;
        }
        $code = (int) ($result['exit_code'] ?? 1);
        $command->update(['status' => $code === 0 ? 'succeeded' : 'failed', 'exit_code' => $code, 'finished_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        $command = $this->command->fresh();
        if ($command !== null && $command->isBusy()) {
            $command->update(['status' => 'failed', 'error' => $e?->getMessage(), 'finished_at' => now()]);
        }
    }
}
