<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A conversation of one administrator with Serfex AI.
 */
#[Fillable(['user_id', 'title', 'status', 'error', 'client_ip'])]
class AiConversation extends Model
{
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<AiMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class)->orderBy('id');
    }

    /**
     * @return HasMany<AiAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(AiAction::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
