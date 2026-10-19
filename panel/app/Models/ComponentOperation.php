<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One install, update or removal of a component (Yazılım Yönetimi); the
 * agent writes its steps to the run log.
 */
#[Fillable(['component', 'action', 'status', 'run', 'error', 'user_id', 'finished_at'])]
class ComponentOperation extends Model
{
    protected $attributes = ['status' => 'queued'];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /**
     * @param  Builder<ComponentOperation>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', ['queued', 'running']);
    }
}
