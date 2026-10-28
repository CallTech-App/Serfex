<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An administrator's own Laravel application, described once and
 * installed on any site: from a Git repository (Serfex deploys it and
 * later updates it) or from a release package (installed once; the
 * application updates itself from its own admin panel). Templates live
 * only in this panel's database.
 */
#[Fillable(['name', 'description', 'source', 'provider', 'git_account_id', 'repository', 'remote_repository', 'private', 'ref_type', 'ref',
    'package_asset', 'package_url', 'latest_version', 'latest_commit', 'latest_package', 'checked_at', 'check_error',
    'php_version', 'database', 'frontend', 'package_manager', 'worker', 'scheduler', 'env_template', 'fields', 'setup_commands',
    'create_admin', 'admin_command', 'migrate', 'hooks', 'update_maintenance', 'update_backup', 'update_rollback',
    'customers_can_install', 'health_path', 'version_file'])]
class AppTemplate extends Model
{
    public const SOURCES = ['git', 'package'];

    /** Databases a template asks for: a new one on a server, SQLite or none. */
    public const DATABASES = ['mysql' => 'Yeni MySQL', 'postgresql' => 'Yeni PostgreSQL', 'sqlite' => 'SQLite', 'none' => 'Yok'];

    /** Where the code comes from: a host with an API, or a typed address. */
    public const PROVIDERS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'other' => 'Başka adres'];

    /** Questions asked at install, at most. */
    public const MAX_FIELDS = 10;

    protected $hidden = ['env_template'];

    protected $attributes = [
        'source' => 'git',
        'provider' => 'github',
        'ref_type' => 'branch',
        'database' => 'mysql',
        'frontend' => true,
        'worker' => true,
        'scheduler' => true,
        'migrate' => true,
        'update_maintenance' => true,
        'update_backup' => true,
        'update_rollback' => true,
        'customers_can_install' => false,
        'health_path' => '/up',
        'version_file' => 'VERSION',
        'fields' => '[]',
        'hooks' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'private' => 'boolean',
            'latest_package' => 'array',
            'checked_at' => 'datetime',
            'frontend' => 'boolean',
            'worker' => 'boolean',
            'scheduler' => 'boolean',
            'env_template' => 'encrypted',
            'fields' => 'array',
            'create_admin' => 'boolean',
            'migrate' => 'boolean',
            'hooks' => 'array',
            'update_maintenance' => 'boolean',
            'update_backup' => 'boolean',
            'update_rollback' => 'boolean',
            'customers_can_install' => 'boolean',
        ];
    }

    /**
     * The connected account the repository was picked with; it reads
     * versions and adds each install's deploy key.
     *
     * @return BelongsTo<GitAccount, $this>
     */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class);
    }

    /**
     * The applications installed from this template.
     *
     * @return HasMany<LaravelApp, $this>
     */
    public function apps(): HasMany
    {
        return $this->hasMany(LaravelApp::class)->whereNull('preview_of_id');
    }

    public function isPackage(): bool
    {
        return $this->source === 'package';
    }

    /**
     * The templates a user may install: administrators all of them,
     * customers those opened to customers.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<AppTemplate>  $query
     */
    public function scopeInstallableBy($query, User $user): void
    {
        if (! $user->is_admin) {
            $query->where('customers_can_install', true);
        }
    }

    /**
     * The questions asked at install, each with key, label, default,
     * required, secret and type (text or file).
     *
     * @return list<array{key: string, label: string, default: string, required: bool, secret: bool, type: string}>
     */
    public function questions(): array
    {
        return array_values(array_map(fn (array $f) => [
            'key' => (string) ($f['key'] ?? ''),
            'label' => (string) ($f['label'] ?? ''),
            'default' => (string) ($f['default'] ?? ''),
            'required' => (bool) ($f['required'] ?? false),
            'secret' => (bool) ($f['secret'] ?? false),
            'type' => ($f['type'] ?? 'text') === 'file' ? 'file' : 'text',
        ], $this->fields ?? []));
    }

    /**
     * The version new installs get: a tag, the branch's newest commit, or
     * the newest release package; null while it has not been read.
     */
    public function versionLabel(): ?string
    {
        if ($this->isPackage()) {
            return $this->latest_version;
        }
        if ($this->ref_type === 'tag') {
            return $this->ref;
        }

        return $this->latest_commit !== null ? substr($this->latest_commit, 0, 7) : null;
    }

    /**
     * True when an application of this template runs an older version
     * than new installs get. Unknown versions are not counted.
     */
    public function isBehind(LaravelApp $app): bool
    {
        $latest = $this->versionLabel();

        return $latest !== null && $app->template_version !== null && $app->template_version !== $latest;
    }

    /**
     * Hook scripts every install and update writes into the application.
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

    /**
     * The name a new database gets after the account's prefix: "magaza".
     */
    public function databaseSuffix(): string
    {
        $slug = Str::limit(trim((string) preg_replace('/[^a-z0-9]+/', '_', Str::ascii(mb_strtolower($this->name))), '_'), 20, '');

        return $slug !== '' ? $slug : 'app';
    }

    /**
     * Where the application lives in its site: releases under laravel/
     * for Git, a plain app/ directory for a package (its own updater
     * renames that directory).
     */
    public function appPath(): string
    {
        return $this->isPackage() ? 'app' : LaravelApp::MANAGED_PATH;
    }

    /**
     * The repository's web address, for links.
     */
    public function webUrl(): ?string
    {
        $repo = (string) ($this->remote_repository ?: $this->repository);
        if ($repo === '') {
            return null;
        }
        if (preg_match('~^https://~', $repo)) {
            return preg_replace('~\.git$~', '', $repo);
        }
        if (preg_match('~^git@([^:]+):(.+?)(\.git)?$~', $repo, $m)) {
            return "https://{$m[1]}/{$m[2]}";
        }
        $host = ['github' => 'github.com', 'gitlab' => 'gitlab.com', 'bitbucket' => 'bitbucket.org'][$this->provider] ?? null;

        return $host ? "https://{$host}/{$repo}" : null;
    }

    /**
     * "owner/name" shown in lists.
     */
    public function repositoryLabel(): string
    {
        if ($this->remote_repository) {
            return $this->remote_repository;
        }
        if ($this->isPackage() && $this->package_url && ! $this->repository) {
            return (string) parse_url($this->package_url, PHP_URL_HOST).(string) parse_url($this->package_url, PHP_URL_PATH);
        }
        $repo = (string) $this->repository;

        return (string) preg_replace(['~^(https://[^/]+/|git@[^:]+:)~', '~\.git$~'], '', $repo);
    }
}
