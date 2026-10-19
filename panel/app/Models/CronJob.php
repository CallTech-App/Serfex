<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A scheduled task of a site: a command the server's cron runs as the
 * site's user on a cron schedule. A trashed task waits out its undo
 * window, already gone from the server.
 */
#[Fillable(['site_id', 'schedule', 'command', 'note', 'enabled', 'timeout', 'last_run_at', 'last_status'])]
class CronJob extends Model
{
    use SoftDeletes;

    /** Ready schedules: cron expression => name. */
    public const PRESETS = [
        '* * * * *' => 'Her dakika',
        '*/5 * * * *' => 'Her 5 dakikada',
        '*/15 * * * *' => 'Her 15 dakikada',
        '0 * * * *' => 'Her saat başı',
        '0 3 * * *' => 'Her gün 03:00',
        '0 4 * * 1' => 'Her pazartesi 04:00',
        '0 5 1 * *' => 'Her ayın 1\'i 05:00',
    ];

    protected $attributes = [
        'enabled' => true,
        'timeout' => 3600,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'timeout' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Tasks on sites the account may see.
     *
     * @param  Builder<CronJob>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('site', fn (Builder $q) => $q->visibleTo($user));
    }

    /**
     * The schedule in words when it is a ready one, else the expression.
     */
    public function scheduleLabel(): string
    {
        return self::PRESETS[$this->schedule] ?? $this->schedule;
    }

    /**
     * When cron next runs it, in the panel's time zone, or null when the
     * task is paused.
     */
    public function nextRun(): ?Carbon
    {
        if (! $this->enabled || ! CronExpression::isValidExpression($this->schedule)) {
            return null;
        }

        return Carbon::instance((new CronExpression($this->schedule))->getNextRunDate(now(config('servik.timezone'))));
    }
}
