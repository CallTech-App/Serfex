<?php

namespace App\Jobs;

use App\Models\LaravelCommand;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\CommandLine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs an Artisan, Composer or Node.js command in a Laravel application as
 * the site's account. The output streams to the command's run log.
 */
class RunLaravelCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1950;

    public function __construct(public LaravelCommand $command) {}

    public function handle(AgentClient $agent): void
    {
        $command = $this->command->fresh(['app.site.systemUser']);
        if ($command === null || $command->status !== 'queued') {
            return;
        }
        $command->update(['status' => 'running']);
        try {
            $result = $agent->call('laravel.run', $command->app->agentApp() + [
                'run' => $command->run,
                'tool' => $command->tool,
                'args' => CommandLine::split($command->command),
            ], config('servik.agent.deploy_timeout'));
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
        if ($command !== null && in_array($command->status, ['queued', 'running'], true)) {
            $command->update(['status' => 'failed', 'error' => $e?->getMessage(), 'finished_at' => now()]);
        }
    }
}
