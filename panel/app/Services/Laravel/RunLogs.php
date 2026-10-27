<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Models\LaravelBackup;
use App\Models\LaravelCommand;
use App\Models\LaravelDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The output of an application's deploys, commands and backups as it is
 * written, read from the agent piece by piece (<x-run-log> in the panel,
 * `serfex deploy --wait` on the command line).
 */
class RunLogs
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The log of $run from $offset on; null when the run is not $app's.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    public function read(LaravelApp $app, string $run, int $offset = 0): ?array
    {
        $owner = LaravelDeployment::where('laravel_app_id', $app->id)->where('run', $run)->first()
            ?? LaravelCommand::where('laravel_app_id', $app->id)->where('run', $run)->first()
            ?? LaravelBackup::where('laravel_app_id', $app->id)->where(fn ($q) => $q->where('run', $run)->orWhere('restore_run', $run))->first();
        if ($owner === null) {
            return null;
        }
        $done = match (true) {
            $owner instanceof LaravelDeployment => ! $owner->status->isBusy(),
            $owner instanceof LaravelBackup => $owner->run === $run ? ! $owner->isRunning() : ! in_array($owner->restore_status, ['queued', 'running'], true),
            $owner->tool === 'tinker' && $owner->status === 'running' => ! $this->tinkerRunning($app, $owner),
            default => ! in_array($owner->status, ['queued', 'running'], true),
        };
        try {
            $chunk = $this->agent->call('laravel.run.read', ['domain' => $app->site->domain, 'run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => false, 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => $done,
        ];
    }

    /**
     * Whether a Tinker session still runs; a session that ended is marked
     * finished.
     */
    private function tinkerRunning(LaravelApp $app, LaravelCommand $session): bool
    {
        try {
            $state = $this->agent->call('laravel.tinker.status', ['domain' => $app->site->domain, 'session' => $session->run]);
        } catch (AgentException) {
            return true;
        }
        if ($state['running'] ?? false) {
            return true;
        }
        $session->update(['status' => 'finished', 'finished_at' => now()]);

        return false;
    }
}
