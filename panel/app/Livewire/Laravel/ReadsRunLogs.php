<?php

namespace App\Livewire\Laravel;

use App\Models\LaravelApp;
use App\Models\LaravelCommand;
use App\Models\LaravelDeployment;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

/**
 * Lets <x-run-log> read the output of a deploy or command of this
 * component's application as it is written.
 */
trait ReadsRunLogs
{
    abstract protected function app(): ?LaravelApp;

    /**
     * The log of $run from $offset on; null when the run is not this
     * application's.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $app = $this->app();
        if ($app === null) {
            return null;
        }
        $owner = LaravelDeployment::where('laravel_app_id', $app->id)->where('run', $run)->first()
            ?? LaravelCommand::where('laravel_app_id', $app->id)->where('run', $run)->first();
        if ($owner === null) {
            return null;
        }
        $done = $owner instanceof LaravelDeployment ? ! $owner->status->isBusy() : ! in_array($owner->status, ['queued', 'running'], true);
        try {
            $chunk = app(AgentClient::class)->call('laravel.run.read', ['domain' => $app->site->domain, 'run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException $e) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => false, 'error' => 'Sunucu ajanına ulaşılamıyor.'];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => $done,
        ];
    }
}
