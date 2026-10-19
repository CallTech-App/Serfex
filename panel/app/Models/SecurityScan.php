<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the security scan: the whole server (every site and the
 * panel's own settings) or one site.
 */
#[Fillable(['scope', 'site_id', 'trigger', 'status', 'sites_total', 'sites_done', 'files', 'clamav', 'error', 'user_id', 'started_at', 'finished_at'])]
class SecurityScan extends Model
{
    protected $attributes = [
        'trigger' => 'manual',
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'clamav' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<SecurityScan>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereIn('status', ['queued', 'running']);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
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
     * @return HasMany<SecurityFinding, $this>
     */
    public function findings(): HasMany
    {
        return $this->hasMany(SecurityFinding::class, 'scan_id');
    }

    public function triggerLabel(): string
    {
        return match ($this->trigger) {
            'schedule' => 'Haftalık tarama',
            'upload' => 'Yüklenen dosya',
            default => $this->user->name ?? 'Elle',
        };
    }
}
