<?php

namespace App\Models;

use App\Services\Tuning\MysqlAdvisor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A setting changed (or to be changed, or dismissed) from an optimization
 * suggestion. from_values and to_values are the agent's database
 * settings that differ, so the change can be undone exactly. The labels
 * are stored language-neutral ("mb:4096") and read in the reader's
 * language.
 */
#[Fillable(['unit', 'key', 'title', 'from_values', 'to_values', 'from_label', 'to_label', 'status', 'scheduled_for', 'applied_at',
    'watch_until', 'finished_at', 'baseline', 'note', 'user_id'])]
class TuningChange extends Model
{
    /** Statuses of a change that is in force. */
    public const IN_FORCE = ['watching', 'applied'];

    protected function casts(): array
    {
        return [
            'from_values' => 'array',
            'to_values' => 'array',
            'baseline' => 'array',
            'scheduled_for' => 'datetime',
            'applied_at' => 'datetime',
            'watch_until' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fromLabel(): Attribute
    {
        return Attribute::get(fn (?string $value) => MysqlAdvisor::label($value));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function toLabel(): Attribute
    {
        return Attribute::get(fn (?string $value) => MysqlAdvisor::label($value));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
