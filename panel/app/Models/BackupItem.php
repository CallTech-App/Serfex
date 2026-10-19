<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archive of a backup set: a site (its files, databases, mail,
 * certificates and the panel's description of it) or the panel itself.
 */
#[Fillable(['backup_id', 'name', 'site_id', 'customer_id', 'size', 'parts', 'incremental', 'skipped', 'warnings', 'meta', 'status', 'error'])]
class BackupItem extends Model
{
    /** Parts of an archive, as the agent names them. */
    public const PARTS = [
        'files' => 'Dosyalar',
        'home' => 'Hesap dosyaları',
        'databases' => 'Veritabanları',
        'mail' => 'E-posta',
        'certificates' => 'SSL sertifikaları',
        'laravel' => 'Laravel veritabanı',
        'database' => 'Panel veritabanı',
        'settings' => 'Panel ayarları',
        'agent' => 'Ajan ayarları',
    ];

    protected $attributes = [
        'parts' => '{}',
        'incremental' => '[]',
        'skipped' => '{}',
        'warnings' => '[]',
        'status' => 'succeeded',
    ];

    protected function casts(): array
    {
        return [
            'parts' => 'array',
            'incremental' => 'array',
            'skipped' => 'array',
            'warnings' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Backup, $this>
     */
    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Archives the user may see: all for an administrator, their own
     * sites' for a customer.
     *
     * @param  Builder<BackupItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_admin) {
            return;
        }
        if ($user->customer_id === null) {
            $query->whereRaw('false');

            return;
        }
        $query->where('name', '!=', 'panel')->where(fn ($q) => $q->where('customer_id', $user->customer_id)
            ->orWhereIn('site_id', Site::visibleTo($user)->select('sites.id')));
    }

    public function isPanel(): bool
    {
        return $this->name === 'panel';
    }

    public function isIncremental(): bool
    {
        return ($this->incremental ?? []) !== [];
    }

    /**
     * "Dosyalar, Veritabanları, E-posta"
     */
    public function partsLabel(): string
    {
        return implode(', ', array_map(fn ($p) => self::PARTS[$p] ?? $p, array_values(array_intersect(array_keys(self::PARTS), array_keys(array_filter($this->parts ?? [], fn ($s) => $s !== null))))));
    }

    /**
     * Parts of Backup::RESTORE_PARTS this archive holds.
     *
     * @return list<string>
     */
    public function restorable(): array
    {
        $parts = $this->parts ?? [];
        $meta = $this->meta ?? [];
        $out = [];
        if (isset($parts['files']) || isset($parts['home'])) {
            $out[] = 'files';
        }
        if (isset($parts['databases']) || isset($parts['laravel'])) {
            $out[] = 'databases';
        }
        // A backup found on a target is described once it is fetched.
        $unknown = $this->meta === null;
        if (isset($parts['mail']) || ($meta['mail'] ?? null) !== null) {
            $out[] = 'mail';
        }
        if (($meta['dns'] ?? null) !== null || $unknown) {
            $out[] = 'dns';
        }
        if (isset($parts['certificates'])) {
            $out[] = 'certificates';
        }

        return $out;
    }

    /**
     * The archive's file name for downloads: kahve.com.tr-20261019-0300.tar.
     */
    public function fileName(): string
    {
        return $this->name.'-'.$this->backup->created_at->format('Ymd-Hi').'.tar';
    }
}
