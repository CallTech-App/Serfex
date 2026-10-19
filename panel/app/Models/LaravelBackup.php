<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backup of one Laravel application: its live release, .env, storage
 * and database, in one archive the agent keeps on the server.
 */
#[Fillable(['laravel_app_id', 'key', 'kind', 'status', 'parts', 'part_sizes', 'size', 'release', 'database_driver', 'run', 'error', 'note', 'user_name', 'finished_at', 'restore_run', 'restore_status', 'restore_error', 'restored_at'])]
class LaravelBackup extends Model
{
    /** What a backup can hold, in order. */
    public const PARTS = ['code' => 'Kod', 'env' => '.env', 'storage' => 'storage', 'database' => 'Veritabanı'];

    public const KINDS = ['manual' => 'Elle', 'scheduled' => 'Zamanlanmış', 'deploy' => 'Dağıtım öncesi', 'restore' => 'Geri yükleme öncesi'];

    protected $attributes = [
        'status' => 'running',
        'part_sizes' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'parts' => 'array',
            'part_sizes' => 'array',
            'finished_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * "Kod, .env, storage, Veritabanı"
     */
    public function partsLabel(): string
    {
        return implode(', ', array_map(fn (string $p) => self::PARTS[$p] ?? $p, array_values(array_intersect(array_keys(self::PARTS), $this->parts ?? []))));
    }

    public function kindLabel(): string
    {
        return ($this->kind === 'deploy' && $this->note) ? $this->note : (self::KINDS[$this->kind] ?? $this->kind);
    }

    /**
     * The archive's name on the server: <key>.tar.
     */
    public function fileName(): string
    {
        return $this->app->site->domain.'-'.$this->created_at->format('Ymd-His').'.tar';
    }
}
