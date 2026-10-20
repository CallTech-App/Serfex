<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One action taken through the panel, the API or by Servik AI: who, what
 * (the message the panel showed), where, and how to undo it while that
 * is still possible.
 */
#[Fillable(['user_id', 'actor', 'via', 'customer_id', 'section', 'message', 'url', 'component', 'undo', 'undone_at', 'ip', 'created_at'])]
class AuditEntry extends Model
{
    /** For how long the log offers Undo, like the pages' own windows. */
    public const UNDO_MINUTES = 10;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'undo' => 'array',
            'undone_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * True while the log may still offer Undo for this entry.
     */
    public function undoable(): bool
    {
        return $this->undo !== null && $this->component !== null && $this->url !== null
            && $this->undone_at === null && $this->created_at->gt(now()->subMinutes(self::UNDO_MINUTES));
    }

    public function viaLabel(): string
    {
        return match ($this->via) {
            'api' => 'API',
            'ai' => 'Servik AI',
            'system' => 'Sistem',
            default => 'Panel',
        };
    }
}
