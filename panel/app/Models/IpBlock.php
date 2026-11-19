<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An address the server drops all traffic from. The agent ends a timed
 * block by itself; removed_at is set when someone lifts it early, with
 * "ended" saying how (see the migration) and removed_by who did it.
 */
#[Fillable(['address', 'country', 'reason', 'site_id', 'kind', 'source', 'user_id', 'expires_at', 'removed_at', 'ended', 'removed_by'])]
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

    /**
     * Blocks that are over: lifted, or run out (Geçmiş).
     *
     * @param  Builder<IpBlock>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNotNull('removed_at')->orWhere('expires_at', '<=', now()));
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
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * When the block stopped being in force; null while it is.
     */
    public function endedAt(): ?Carbon
    {
        return $this->removed_at ?? ($this->expires_at?->isPast() ? $this->expires_at : null);
    }

    /**
     * How the block ended, for Geçmiş.
     */
    public function endedLabel(): string
    {
        if ($this->removed_at === null) {
            return __('Süresi doldu');
        }

        return match ($this->ended) {
            'allowed' => __('İzinlilere eklendi'),
            'replaced' => __('Süresi değiştirildi'),
            'sync' => __('Fail2ban kaldırdı'),
            default => $this->remover !== null ? __(':name kaldırdı', ['name' => $this->remover->name]) : __('Kaldırıldı'),
        };
    }

    /**
     * How long the block was set for, in minutes; null for one without
     * an end. "Yeniden engelle" blocks for this long again.
     */
    public function minutes(): ?int
    {
        return $this->expires_at === null ? null : max(1, (int) round($this->created_at->diffInSeconds($this->expires_at) / 60));
    }

    /**
     * Who blocked it, for lists.
     */
    public function byLabel(): string
    {
        return match ($this->source) {
            'ai' => 'Serfex AI',
            'api' => 'API',
            'fail2ban' => 'Fail2ban',
            default => $this->user->name ?? 'Panel',
        };
    }
}
