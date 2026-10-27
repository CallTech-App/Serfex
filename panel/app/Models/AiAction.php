<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change Serfex AI made on its own (kind auto: an IP block or a
 * temporary limit) or proposed for a person to apply (kind proposal).
 */
#[Fillable(['ai_conversation_id', 'tool_use_id', 'kind', 'action', 'params', 'title', 'reason', 'status', 'undo', 'error',
    'user_id', 'decided_at', 'undone_at'])]
class AiAction extends Model
{
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'undo' => 'array',
            'decided_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'ai_conversation_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
