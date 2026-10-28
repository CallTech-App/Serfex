<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of Siteyi kopyala: a staging copy made (create), brought up to
 * date from its live site (refresh), or put on the live site (publish).
 * Its output is the agent's backup run log named run.
 */
#[Fillable(['source_id', 'site_id', 'domain', 'kind', 'parts', 'run', 'status', 'notes', 'error', 'backup_id', 'restore_id', 'user_id', 'started_at', 'finished_at'])]
class SiteCopy extends Model
{
    public const CREATE = 'create';

    public const REFRESH = 'refresh';

    public const PUBLISH = 'publish';

    public const PARTS = ['files', 'databases'];

    protected function casts(): array
    {
        return [
            'parts' => 'array',
            'notes' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The live site.
     *
     * @return BelongsTo<Site, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'source_id');
    }

    /**
     * The staging copy; null once it is deleted (or when making it failed).
     *
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The live site's backup taken before a publish.
     *
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * The restore that undid a publish.
     *
     * @return BelongsTo<BackupRestore, $this>
     */
    public function restore(): BelongsTo
    {
        return $this->belongsTo(BackupRestore::class, 'restore_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function label(): string
    {
        return match ($this->kind) {
            self::CREATE => __('Kopya oluşturma'),
            self::REFRESH => __('Kaynaktan yenileme'),
            default => __('Canlı siteye aktarma'),
        };
    }
}
