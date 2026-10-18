<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use Database\Factories\LaravelAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A Laravel application on a site. Servik deploys it into releases under
 * laravel/ (path laravel/current), or manages one found on the site where
 * it is.
 */
#[Fillable(['site_id', 'path', 'status', 'source', 'package', 'repository', 'branch', 'frontend', 'package_manager', 'migrate', 'hooks',
    'health_path', 'auto_rollback', 'keep_releases', 'auto_deploy', 'deploy_key', 'laravel_version', 'php_requirement', 'current_release',
    'scheduler', 'maintenance_secret', 'maintenance_retry', 'saved_commands', 'processes_state', 'processes_error', 'last_error', 'install_env', 'installed_at'])]
class LaravelApp extends Model
{
    /** @use HasFactory<LaravelAppFactory> */
    use HasFactory;

    /** Where the live release of an application Servik deploys is. */
    public const MANAGED_PATH = 'laravel/current';

    protected $hidden = ['install_env', 'deploy_key', 'webhook_token'];

    protected $attributes = [
        'status' => 'installing',
        'hooks' => '{}',
        'saved_commands' => '{}',
        'health_path' => '/up',
        'frontend' => true,
        'migrate' => true,
        'auto_rollback' => true,
        'keep_releases' => 5,
        'processes_state' => 'ok',
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
            'installed_at' => 'datetime',
            'install_env' => 'encrypted:array',
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
     * Applications that need attention: a failed install or last deploy, or
     * workers that could not be applied. Shown next to the menu item.
     */
    public static function attentionCount(): int
    {
        return static::query()
            ->where(fn ($q) => $q->where('status', LaravelAppStatus::Failed)
                ->orWhere('processes_state', 'failed')
                ->orWhereHas('latestDeployment', fn ($d) => $d->whereIn('status', [DeploymentStatus::Failed, DeploymentStatus::RolledBack, DeploymentStatus::Unhealthy])))
            ->count();
    }

    /**
     * True when Servik deploys the application into releases.
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

    /**
     * github, gitlab, bitbucket or null, from the repository address.
     */
    public function provider(): ?string
    {
        $host = $this->repositoryHost();
        foreach (['github', 'gitlab', 'bitbucket'] as $p) {
            if ($host !== null && str_contains($host, $p)) {
                return $p;
            }
        }

        return null;
    }

    public function providerLabel(): string
    {
        return match ($this->provider()) {
            'github' => 'GitHub',
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            default => 'Git',
        };
    }

    /**
     * owner/name of the repository, for display.
     */
    public function repositoryName(): ?string
    {
        if ($this->repository === null) {
            return null;
        }
        $path = preg_replace('~^(?:https://[^/]+/|ssh://[^/]+/|[^@]+@[^:]+:)~', '', $this->repository);

        return Str::of($path)->trim('/')->beforeLast('.git')->value() ?: $this->repository;
    }

    private function repositoryHost(): ?string
    {
        if ($this->repository === null) {
            return null;
        }
        if (preg_match('~^(?:https://|ssh://[^@]+@)([^/:]+)~', $this->repository, $m) || preg_match('~^[^@]+@([^:]+):~', $this->repository, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Where the repository's deploy keys are added, when the provider is known.
     */
    public function deployKeysUrl(): ?string
    {
        $host = $this->repositoryHost();
        $name = $this->repositoryName();

        return match ($this->provider()) {
            'github' => "https://{$host}/{$name}/settings/keys",
            'gitlab' => "https://{$host}/{$name}/-/settings/repository",
            'bitbucket' => "https://{$host}/{$name}/admin/access-keys/",
            default => null,
        };
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
