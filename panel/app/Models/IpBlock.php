<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address the server drops all traffic from. The agent ends a timed
 * block by itself; removed_at is set when someone lifts it early.
 */
#[Fillable(['address', 'reason', 'site_id', 'kind', 'source', 'user_id', 'expires_at', 'removed_at'])]
class IpBlock extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Blocks in force now.
     *
     * @param  Builder<IpBlock>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->removed_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
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
     * Who blocked it, for lists.
     */
    public function byLabel(): string
    {
        return match ($this->source) {
            'ai' => 'Servik AI',
            'api' => 'API',
            default => $this->user->name ?? 'Panel',
        };
    }
}
