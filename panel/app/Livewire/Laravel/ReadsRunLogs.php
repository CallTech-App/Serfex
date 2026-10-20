<?php

namespace App\Livewire\Laravel;

use App\Models\LaravelApp;
use App\Services\Laravel\RunLogs;
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

        return $app === null ? null : app(RunLogs::class)->read($app, $run, $offset);
    }
}
