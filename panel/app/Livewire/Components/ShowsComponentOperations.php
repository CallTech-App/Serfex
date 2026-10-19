<?php

namespace App\Livewire\Components;

use App\Models\ComponentOperation;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

/**
 * The progress window of a software operation (livewire.components.progress):
 * which operation is shown and its run log, read from the agent as it is
 * written.
 */
trait ShowsComponentOperations
{
    /** The operation whose progress is shown. */
    public ?int $operationId = null;

    /**
     * Shows the log of the operation running on a row.
     */
    public function show(int $operationId): void
    {
        if (ComponentOperation::whereKey($operationId)->exists()) {
            $this->operationId = $operationId;
        }
    }

    public function closeOperation(): void
    {
        $this->operationId = null;
    }

    /**
     * The run log of the operation shown, from $offset on.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, status: string, error?: ?string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $op = ComponentOperation::where('run', $run)->first();
        if ($op === null) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('component.run.read', ['run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => ! $op->isRunning(), 'status' => $op->status, 'error' => 'Sunucu ajanına ulaşılamıyor.'];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $op->isRunning(),
            'status' => $op->status,
            'error' => $op->status === 'failed' ? $op->error : null,
        ];
    }
}
