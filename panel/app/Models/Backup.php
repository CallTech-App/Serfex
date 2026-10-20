<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A backup set taken at one time: a whole-server backup (every site and
 * the panel) or one site's. Each archive in it is a BackupItem.
 */
#[Fillable(['key', 'scope', 'type', 'trigger', 'base_id', 'target_id', 'local', 'remote', 'encrypted', 'passphrase', 'remote_prefix', 'status', 'size', 'seconds',
    'run', 'error', 'source_host', 'user_id', 'customer_id', 'started_at', 'finished_at', 'cached_at'])]
#[Hidden(['passphrase'])]
class Backup extends Model
{
    public const TRIGGERS = ['scheduled' => 'Zamanlanmış', 'manual' => 'Elle', 'safety' => 'Geri yükleme öncesi', 'update' => 'Güncelleme öncesi', 'import' => 'İçe aktarılan'];

    /** What a site restore can put back, in the order they are applied. */
    public const RESTORE_PARTS = ['files' => 'Dosyalar', 'databases' => 'Veritabanları', 'mail' => 'E-posta', 'dns' => 'DNS', 'certificates' => 'SSL sertifikaları'];

    protected $attributes = [
        'type' => 'full',
        'local' => true,
        'remote' => false,
        'encrypted' => false,
    ];

    protected function casts(): array
    {
        return [
            'local' => 'boolean',
            'remote' => 'boolean',
            'encrypted' => 'boolean',
            'passphrase' => 'encrypted',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cached_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BackupItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BackupItem::class);
    }

    /**
     * @return BelongsTo<BackupTarget, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(BackupTarget::class, 'target_id')->withTrashed();
    }

    /**
     * The backup an incremental continues from.
     *
     * @return BelongsTo<Backup, $this>
     */
    public function base(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'base_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Backups that can be listed, restored and downloaded.
     *
     * @param  Builder<Backup>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereIn('status', ['succeeded', 'partial']);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['succeeded', 'partial'], true);
    }

    /**
     * True when the archives can be read on this server now.
     */
    public function onServer(): bool
    {
        return $this->local || $this->cached_at !== null;
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    public function typeLabel(): string
    {
        return $this->type === 'incremental' ? __('Artımlı') : __('Tam');
    }

    /**
     * "Yerel disk", "Wasabi", "Yerel disk + Wasabi".
     */
    public function destinationLabel(): string
    {
        $remote = $this->target?->name ?? __('Uzak hedef');

        return match (true) {
            $this->local && $this->remote => __('Yerel disk + :remote', ['remote' => $remote]),
            $this->remote => $remote,
            default => __('Yerel disk'),
        };
    }
}
