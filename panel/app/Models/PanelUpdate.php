<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One update of Servik to a newer release, or a return to the previous
 * one. The agent performs it and writes its steps to the run log.
 */
#[Fillable(['kind', 'from_version', 'to_version', 'status', 'run', 'error', 'backup_id', 'user_id', 'started_at', 'finished_at'])]
class PanelUpdate extends Model
{
    public const RUNNING = ['queued', 'backing_up', 'running'];

    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, self::RUNNING, true);
    }

    /**
     * @param  Builder<PanelUpdate>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', self::RUNNING);
    }

    /**
     * How long it took, as text ("1 dk 48 sn").
     */
    public function duration(): ?string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }
        $s = max(0, (int) $this->started_at->diffInSeconds($this->finished_at));

        return $s >= 60 ? __(':minutes dk :seconds sn', ['minutes' => intdiv($s, 60), 'seconds' => $s % 60]) : __(':seconds sn', ['seconds' => $s]);
    }
}
