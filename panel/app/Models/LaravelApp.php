<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Models\Concerns\HasGitRepository;
use Database\Factories\LaravelAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A Laravel application on a site. Serfex deploys it into releases under
 * laravel/ (path laravel/current), or manages one found on the site where
 * it is.
 */
#[Fillable(['site_id', 'path', 'status', 'source', 'package', 'repository', 'branch', 'frontend', 'package_manager', 'migrate', 'hooks',
    'health_path', 'auto_rollback', 'keep_releases', 'auto_deploy', 'deploy_key', 'laravel_version', 'php_requirement', 'current_release',
    'scheduler', 'skipped_tasks', 'maintenance_secret', 'maintenance_retry', 'saved_commands', 'processes_state', 'processes_error', 'last_error', 'install_env', 'installed_at',
    'preview_of_id', 'previews_enabled', 'preview_database', 'preview_seed', 'preview_env', 'preview_limit',
    'maintenance_mode', 'maintenance_on', 'maintenance_allow', 'maintenance_html',
    'backup_schedule', 'backup_time', 'backup_keep', 'backup_before_deploy', 'database_id', 'redis_port', 'redis_password',
    'git_account_id', 'remote_repository', 'remote_key_id', 'remote_hook_id'])]
class LaravelApp extends Model
{
    /** @use HasFactory<LaravelAppFactory> */
    use HasFactory;

    use HasGitRepository;

    /** Where the live release of an application Serfex deploys is. */
    public const MANAGED_PATH = 'laravel/current';

    protected $hidden = ['install_env', 'deploy_key', 'webhook_token', 'redis_password'];

    protected $attributes = [
        'status' => 'installing',
        'hooks' => '{}',
        'saved_commands' => '{}',
        'skipped_tasks' => '[]',
        'health_path' => '/up',
        'frontend' => true,
        'migrate' => true,
        'auto_rollback' => true,
        'keep_releases' => 5,
        'processes_state' => 'ok',
        'preview_database' => 'sqlite',
        'preview_seed' => true,
        'preview_limit' => 5,
        'maintenance_mode' => 'serfex',
        'backup_schedule' => 'off',
        'backup_time' => '03:00',
        'backup_keep' => 7,
    ];

    /** How visitors see maintenance: in Nginx (serfex, custom) or the application's own page. */
    public const MAINTENANCE_MODES = [
        'serfex' => 'Serfex bakım sayfası',
        'custom' => 'Özel HTML',
        'app' => 'Uygulamanın kendi sayfası',
    ];

    protected static function booted(): void
    {
        static::creating(function (LaravelApp $app) {
            $app->webhook_token ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => LaravelAppStatus::class,
            'hooks' => 'array',
            'saved_commands' => 'array',
            'frontend' => 'boolean',
            'migrate' => 'boolean',
            'auto_rollback' => 'boolean',
            'auto_deploy' => 'boolean',
            'scheduler' => 'boolean',
            'skipped_tasks' => 'array',
            'installed_at' => 'datetime',
            'install_env' => 'encrypted:array',
            'previews_enabled' => 'boolean',
            'preview_seed' => 'boolean',
            'maintenance_on' => 'boolean',
            'backup_before_deploy' => 'boolean',
            'redis_password' => 'encrypted',
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
     * The connected account the repository was picked with, if it was.
     *
     * @return BelongsTo<GitAccount, $this>
     */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class);
    }

    /**
     * The database Serfex created for the application, if any.
     *
     * @return BelongsTo<Database, $this>
     */
    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    /**
     * @return HasMany<LaravelDeployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(LaravelDeployment::class)->orderByDesc('number');
    }

    /**
     * @return HasOne<LaravelDeployment, $this>
     */
    public function latestDeployment(): HasOne
    {
        return $this->hasOne(LaravelDeployment::class)->latestOfMany('number');
    }

    /**
     * @return HasMany<LaravelWorker, $this>
     */
    public function workers(): HasMany
    {
        return $this->hasMany(LaravelWorker::class)->orderBy('name');
    }

    /**
     * @return HasMany<LaravelCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(LaravelCommand::class)->latest('id');
    }

    /**
     * @return HasMany<LaravelEnvVersion, $this>
     */
    public function envVersions(): HasMany
    {
        return $this->hasMany(LaravelEnvVersion::class)->latest('id');
    }

    /**
     * @return HasMany<LaravelProcess, $this>
     */
    public function processes(): HasMany
    {
        return $this->hasMany(LaravelProcess::class)->orderBy('id');
    }

    /**
     * @return HasMany<LaravelPreview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(LaravelPreview::class)->latest('id');
    }

    /**
     * @return HasMany<LaravelBackup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(LaravelBackup::class)->latest('id');
    }

    /**
     * @return HasMany<LaravelAppMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(LaravelAppMember::class);
    }

    /**
     * @return HasMany<LaravelApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(LaravelApiToken::class)->latest('id');
    }

    /**
     * The application a preview environment previews.
     *
     * @return BelongsTo<LaravelApp, $this>
     */
    public function previewOf(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'preview_of_id');
    }

    public function isPreview(): bool
    {
        return $this->preview_of_id !== null;
    }

    /**
     * True when Nginx shows the maintenance page (Serfex's or custom HTML)
     * rather than php artisan down.
     */
    public function nginxMaintenance(): bool
    {
        return $this->maintenance_on && $this->maintenance_mode !== 'app';
    }

    /**
     * The IP addresses and networks let through during maintenance.
     *
     * @return list<string>
     */
    public function maintenanceAllowList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->maintenance_allow) ?: [])));
    }

    /**
     * Applications that need attention: a failed install or last deploy, or
     * workers that could not be applied. Shown next to the menu item.
     */
    public static function attentionCount(): int
    {
        return static::query()
            ->whereNull('preview_of_id')
            ->where(fn ($q) => $q->where('status', LaravelAppStatus::Failed)
                ->orWhere('processes_state', 'failed')
                ->orWhereHas('latestDeployment', fn ($d) => $d->whereIn('status', [DeploymentStatus::Failed, DeploymentStatus::RolledBack, DeploymentStatus::Unhealthy])))
            ->count();
    }

    /**
     * True when Serfex deploys the application into releases.
     */
    public function isManaged(): bool
    {
        return $this->path === self::MANAGED_PATH;
    }

    /**
     * True when a new release can be deployed: the code comes from Git.
     */
    public function canDeploy(): bool
    {
        return $this->repository !== null && ! $this->status->isBusy() && ! $this->hasRunningDeployment();
    }

    public function hasRunningDeployment(): bool
    {
        return $this->deployments()->whereIn('status', [DeploymentStatus::Queued, DeploymentStatus::Running])->exists();
    }

    /**
     * The site's directory Nginx should serve for this application.
     */
    public function webRoot(): string
    {
        return $this->path.'/public';
    }

    /**
     * Parameters naming the site for the agent's laravel.* methods.
     *
     * @return array{domain: string, user: string, php_version: string}
     */
    public function target(): array
    {
        return [
            'domain' => $this->site->domain,
            'user' => $this->site->systemUser->name,
            'php_version' => $this->site->php_version,
        ];
    }

    /**
     * target() plus the application's directory.
     *
     * @return array{domain: string, user: string, php_version: string, path: string}
     */
    public function agentApp(): array
    {
        return $this->target() + ['path' => $this->path];
    }

    /**
     * Absolute directory of the application on the server.
     */
    public function directory(): string
    {
        return '/home/'.$this->site->systemUser->name.'/web/'.$this->site->domain.'/'.$this->path;
    }

    public function webhookUrl(): string
    {
        return route('laravel.webhook', $this->webhook_token);
    }

    /**
     * The next deployment number.
     */
    public function nextNumber(): int
    {
        return (int) $this->deployments()->max('number') + 1;
    }

    /**
     * Customer hook scripts, with every stage present.
     *
     * @return array{before_build: string, before_activate: string, after_activate: string}
     */
    public function hookScripts(): array
    {
        $hooks = $this->hooks ?? [];

        return [
            'before_build' => (string) ($hooks['before_build'] ?? ''),
            'before_activate' => (string) ($hooks['before_activate'] ?? ''),
            'after_activate' => (string) ($hooks['after_activate'] ?? ''),
        ];
    }
}
