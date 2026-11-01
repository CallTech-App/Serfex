<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One change of a site's domain (Alan adını değiştir), or the undo of one.
 * Its output is the agent's backup run log named run.
 */
#[Fillable(['site_id', 'from_domain', 'to_domain', 'options', 'run', 'status', 'notes', 'before', 'error', 'undo_of_id', 'backup_id', 'user_id', 'started_at', 'finished_at'])]
class SiteRename extends Model
{
    /** What happens to the old name: a redirect to the new one. */
    public const OLD_REDIRECT = 'redirect';

    /** The old name keeps opening the site (an additional domain). */
    public const OLD_ALIAS = 'alias';

    /** The old name goes. */
    public const OLD_NONE = 'none';

    public const OLD = [self::OLD_REDIRECT, self::OLD_ALIAS, self::OLD_NONE];

    /** How long a rename can be undone, in days. */
    public const UNDO_DAYS = 7;

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'notes' => 'array',
            'before' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SiteRename, $this>
     */
    public function undoOf(): BelongsTo
    {
        return $this->belongsTo(SiteRename::class, 'undo_of_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /**
     * Until when the rename can be undone from the site's page.
     */
    public function undoUntil(): ?Carbon
    {
        return $this->status === 'succeeded' && $this->undo_of_id === null && $this->finished_at !== null
            ? $this->finished_at->copy()->addDays(self::UNDO_DAYS)
            : null;
    }
}
