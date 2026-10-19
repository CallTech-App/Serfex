<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message of a conversation: a question, Claude's answer as the API
 * returned it, or the panel's answers to Claude's tool calls.
 */
#[Fillable(['ai_conversation_id', 'kind', 'content', 'text', 'model', 'stop_reason'])]
class AiMessage extends Model
{
    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id');
    }

    /**
     * The message as the Messages API expects it.
     *
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    public function toApi(): array
    {
        return ['role' => $this->kind === 'assistant' ? 'assistant' : 'user', 'content' => $this->content];
    }

    /**
     * Tool calls in an answer.
     *
     * @return list<array<string, mixed>>
     */
    public function toolUses(): array
    {
        return array_values(array_filter($this->content, fn ($b) => ($b['type'] ?? null) === 'tool_use'));
    }
}
