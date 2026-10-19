<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parts of a site put back from a backup archive, with the backup of the
 * state before it (for Undo) and the agent's progress log.
 */
#[Fillable(['backup_item_id', 'site_id', 'domain', 'parts', 'safety_backup_id', 'recreated', 'status', 'run', 'result', 'error', 'user_id', 'finished_at'])]
class BackupRestore extends Model
{
    protected $attributes = [
        'status' => 'queued',
        'recreated' => false,
    ];

    protected function casts(): array
    {
        return [
            'parts' => 'array',
            'result' => 'array',
            'recreated' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BackupItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(BackupItem::class, 'backup_item_id');
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
    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'safety_backup_id');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function partsLabel(): string
    {
        return implode(', ', array_map(fn ($p) => Backup::RESTORE_PARTS[$p] ?? $p, $this->parts ?? []));
    }
}
