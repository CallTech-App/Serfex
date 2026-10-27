<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of Serfex AI's audit log: something it read, proposed or did,
 * or what a person decided about a proposal.
 */
#[Fillable(['ai_conversation_id', 'user_id', 'type', 'tool', 'summary', 'data', 'created_at'])]
class AiEvent extends Model
{
    public const UPDATED_AT = null;

    /** Type => label in the panel. */
    public const TYPES = [
        'read' => 'Okudu',
        'auto' => 'Kendisi yaptı',
        'proposed' => 'Önerdi',
        'applied' => 'Uygulandı',
        'rejected' => 'Reddedildi',
        'undone' => 'Geri alındı',
        'failed' => 'Başarısız',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
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

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function record(string $type, string $summary, ?AiConversation $conversation = null, ?User $user = null,
        ?string $tool = null, ?array $data = null): self
    {
        return self::create([
            'ai_conversation_id' => $conversation?->id,
            'user_id' => $user?->id ?? $conversation?->user_id,
            'type' => $type,
            'tool' => $tool,
            'summary' => mb_substr($summary, 0, 300),
            'data' => $data,
        ]);
    }
}
