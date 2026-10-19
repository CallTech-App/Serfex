<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary cap on a site's CPU, memory or PHP workers. The agent ends
 * it at expires_at by itself.
 */
#[Fillable(['site_id', 'cpu', 'memory_mb', 'php_workers', 'reason', 'source', 'user_id', 'expires_at', 'removed_at'])]
class ResourceLimit extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Limits in force now.
     *
     * @param  Builder<ResourceLimit>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->removed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "CPU %50 · 1 GB bellek · 4 PHP işçisi".
     */
    public function summary(): string
    {
        $parts = [];
        if ($this->cpu) {
            $parts[] = 'CPU %'.$this->cpu;
        }
        if ($this->memory_mb) {
            $parts[] = ($this->memory_mb >= 1024 && $this->memory_mb % 1024 === 0 ? ($this->memory_mb / 1024).' GB' : $this->memory_mb.' MB').' bellek';
        }
        if ($this->php_workers) {
            $parts[] = $this->php_workers.' PHP işçisi';
        }

        return implode(' · ', $parts);
    }
}
