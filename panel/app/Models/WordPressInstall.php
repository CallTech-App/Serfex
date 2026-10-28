<?php

namespace App\Models;

use App\Enums\WordPressOperationStatus;
use App\Models\Concerns\OwnedThroughSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The WordPress of a site (WordPress araç seti): installed by Serfex or
 * found on the site. The agent's last reading of it is kept in state, so
 * pages show it at once and refresh it in the background.
 */
#[Fillable(['site_id', 'path', 'status', 'database_id', 'version', 'core_update', 'update_count', 'maintenance', 'problem', 'state', 'checked_at'])]
class WordPressInstall extends Model
{
    use OwnedThroughSite;

    public const INSTALLING = 'installing';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    protected $table = 'wordpress_installs';

    protected $attributes = [
        'status' => self::ACTIVE,
        'update_count' => 0,
        'maintenance' => false,
    ];

    protected function casts(): array
    {
        return [
            'state' => 'array',
            'maintenance' => 'boolean',
            'checked_at' => 'datetime',
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
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return HasMany<WordPressOperation, $this>
     */
    public function operations(): HasMany
    {
        return $this->hasMany(WordPressOperation::class, 'wordpress_install_id')->orderByDesc('id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function hasRunningOperation(): bool
    {
        return $this->operations()->whereIn('status', [WordPressOperationStatus::Queued, WordPressOperationStatus::Running])->exists();
    }

    /**
     * The installation as the agent's wordpress methods name it.
     *
     * @return array{domain: string, user: string, php_version: string, path: string}
     */
    public function target(): array
    {
        return [
            'domain' => $this->site->domain,
            'user' => $this->site->systemUser->name,
            'php_version' => $this->site->php_version,
            'path' => $this->path,
        ];
    }

    /**
     * Where the folder is on the site: / for the web root, /blog/ for a
     * folder inside it; null when it is outside the web root.
     */
    public function webPath(): ?string
    {
        $root = $this->site->web_root ?? 'public_html';
        if ($this->path === $root) {
            return '/';
        }

        return str_starts_with($this->path, $root.'/') ? '/'.substr($this->path, strlen($root) + 1).'/' : null;
    }

    /**
     * Plugins or themes from the last reading.
     *
     * @return list<array{name: string, title: string, version: string, update: string, status: string, auto_update: bool}>
     */
    public function items(string $kind): array
    {
        return array_values((array) ($this->state[$kind] ?? []));
    }
}
