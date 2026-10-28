<?php

namespace App\Models;

use App\Enums\WordPressOperationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An install, update or return to a restore point of a site's WordPress.
 * Its output is the agent's run log named run. An update keeps its
 * restore point (point) on the server for RESTORE_DAYS.
 */
#[Fillable(['wordpress_install_id', 'kind', 'run', 'status', 'request', 'changes', 'point', 'restored_at', 'error', 'user_id', 'triggered_by', 'started_at', 'finished_at'])]
class WordPressOperation extends Model
{
    /** How long the agent keeps restore points. */
    public const RESTORE_DAYS = 7;

    protected $table = 'wordpress_operations';

    protected $attributes = [
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'status' => WordPressOperationStatus::class,
            'request' => 'array',
            'changes' => 'array',
            'restored_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WordPressInstall, $this>
     */
    public function install(): BelongsTo
    {
        return $this->belongsTo(WordPressInstall::class, 'wordpress_install_id');
    }

    /**
     * True while the update's restore point can be put back.
     */
    public function canRestore(): bool
    {
        return $this->kind === 'update' && $this->point !== null && $this->restored_at === null
            && in_array($this->status, [WordPressOperationStatus::Succeeded, WordPressOperationStatus::Partial], true)
            && ($this->finished_at ?? $this->created_at)->gt(now()->subDays(self::RESTORE_DAYS)->addHour());
    }
}
