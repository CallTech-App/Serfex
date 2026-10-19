<?php

namespace App\Jobs;

use App\Models\AiConversation;
use App\Services\Ai\Assistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Lets Servik AI answer the newest question of a conversation.
 */
class RunAssistantTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public AiConversation $conversation) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ai-'.$this->conversation->id))->releaseAfter(5)->expireAfter($this->timeout)];
    }

    public function handle(Assistant $assistant): void
    {
        $assistant->run($this->conversation);
    }

    public function failed(?Throwable $e): void
    {
        $this->conversation->update(['status' => 'failed', 'error' => 'Yanıt yarıda kaldı. Yeniden deneyin.']);
    }
}
