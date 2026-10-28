<?php

namespace App\Services\Laravel;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Jobs\DeployLaravelApp;
use App\Jobs\ProvisionSite;
use App\Jobs\RemoveLaravelApp;
use App\Jobs\RollbackLaravelApp;
use App\Jobs\RunLaravelCommand;
use App\Jobs\SyncLaravelProcesses;
use App\Models\LaravelApp;
use App\Models\LaravelCommand;
use App\Models\LaravelDeployment;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Installs, adopts, deploys and removes Laravel applications, then queues
 * the agent work that makes the server follow.
 */
class LaravelApps
{
    /**
     * Repository addresses the wizard accepts: owner/name on a known host,
     * https://, ssh:// or scp-like git@host:path.
     */
    public const REPOSITORY_PATTERN = '#^([\w.-]+/[\w.-]+|https://[A-Za-z0-9.-]+(:\d+)?/[\w.~/-]+|ssh://[\w.-]+@[A-Za-z0-9.-]+(:\d+)?/[\w.~/-]+|[\w.-]+@[A-Za-z0-9.-]+:[\w.~/-]+)$#';

    /** Starter kits offered for a new project, as Composer packages. */
    public const STARTER_KITS = [
        '' => ['Yok', 'laravel/laravel'],
        'react' => ['React', 'laravel/react-starter-kit'],
        'vue' => ['Vue', 'laravel/vue-starter-kit'],
        'livewire' => ['Livewire', 'laravel/livewire-starter-kit'],
    ];

    public const PACKAGE_MANAGERS = ['npm', 'pnpm', 'yarn', 'bun'];

    /** Database drivers of an existing database connection. */
    public const DB_DRIVERS = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'pgsql' => 'PostgreSQL'];

    /**
     * Install a new application on a site: a new project (create) or the
     * code of a Git repository (git).
     *
     * @param array{
     *     source: 'create'|'git',
     *     kit?: string,
     *     repository?: string,
     *     branch?: string,
     *     php_version?: string,
     *     database: 'sqlite'|'existing'|'none',
     *     db?: array{driver: string, host: string, port: string, database: string, username: string, password: string},
     *     frontend: bool,
     *     package_manager?: string,
     *     worker: bool,
     *     scheduler: bool,
     * } $options
     */
    public function install(Site $site, array $options, ?User $user = null): LaravelApp
    {
        return DB::transaction(function () use ($site, $options, $user) {
            if (! empty($options['php_version']) && $options['php_version'] !== $site->php_version) {
                // Applied with the web root once the first release is live.
                $site->update(['php_version' => $options['php_version']]);
            }
            $git = $options['source'] === 'git';
            $app = LaravelApp::create([
                'site_id' => $site->id,
                'path' => LaravelApp::MANAGED_PATH,
                'status' => LaravelAppStatus::Installing,
                'source' => $git ? 'git' : 'create',
                'package' => $git ? null : (self::STARTER_KITS[$options['kit'] ?? ''][1] ?? 'laravel/laravel'),
                'repository' => $git ? $options['repository'] : null,
                'branch' => $git ? ($options['branch'] ?: null) : null,
                'frontend' => $options['frontend'],
                'package_manager' => $options['package_manager'] ?? null,
                'migrate' => $options['database'] !== 'none',
                'scheduler' => $options['scheduler'],
                'install_env' => $this->installEnv($site, $options),
            ]);
            if ($options['worker']) {
                $app->workers()->create(['name' => 'default']);
            }
            $deployment = $this->newDeployment($app, 'install', $app->branch, $user, $app->migrate, $app->frontend);
            DeployLaravelApp::dispatch($deployment)->afterCommit();

            return $app;
        });
    }

    /**
     * Try a failed install again, with the same choices.
     */
    public function retryInstall(LaravelApp $app, ?User $user = null): LaravelDeployment
    {
        $app->update(['status' => LaravelAppStatus::Installing, 'last_error' => null]);
        $deployment = $this->newDeployment($app, 'install', $app->branch, $user, $app->migrate, $app->frontend);
        DeployLaravelApp::dispatch($deployment);

        return $deployment;
    }

    /**
     * Manage an application found on a site where it is. The site starts
     * serving its public/ directory.
     *
     * @param  array{path: string, laravel?: string, php_requirement?: string, package_manager?: string}  $found
     */
    public function adopt(Site $site, array $found): LaravelApp
    {
        $app = LaravelApp::create([
            'site_id' => $site->id,
            'path' => $found['path'],
            'status' => LaravelAppStatus::Active,
            'source' => 'existing',
            'frontend' => ($found['package_manager'] ?? '') !== '',
            'package_manager' => ($found['package_manager'] ?? '') ?: null,
            'laravel_version' => ($found['laravel'] ?? '') ?: null,
            'php_requirement' => ($found['php_requirement'] ?? '') ?: null,
            'installed_at' => now(),
        ]);
        $this->serveFrom($site, $app->webRoot());

        return $app;
    }

    /**
     * Stop managing an adopted application right after adopting it (Undo):
     * the site serves what it served before.
     */
    public function forget(LaravelApp $app, ?string $webRoot): void
    {
        $site = $app->site;
        $app->delete();
        $this->serveFrom($site, $webRoot);
    }

    /**
     * Deploy a new release from the repository.
     */
    public function deploy(LaravelApp $app, ?string $ref, ?User $user = null, ?bool $migrate = null, ?bool $frontend = null, ?string $triggeredBy = null): LaravelDeployment
    {
        $deployment = $this->newDeployment($app, 'deploy', $ref ?: $app->branch, $user, $migrate ?? $app->migrate, $frontend ?? $app->frontend, $triggeredBy);
        DeployLaravelApp::dispatch($deployment);

        return $deployment;
    }

    /**
     * Put an earlier release live again.
     */
    public function rollback(LaravelApp $app, LaravelDeployment $to, ?User $user = null): LaravelDeployment
    {
        $deployment = LaravelDeployment::create([
            'laravel_app_id' => $app->id,
            'number' => $app->nextNumber(),
            'kind' => 'rollback',
            'status' => DeploymentStatus::Queued,
            'ref' => $to->ref,
            'commit' => $to->commit,
            'message' => $to->message,
            'release' => $to->release,
            'triggered_by' => $user?->name,
            'run' => 'rollback-'.Str::lower(Str::random(12)),
            'migrate' => false,
            'frontend' => false,
        ]);
        RollbackLaravelApp::dispatch($deployment);

        return $deployment;
    }

    /**
     * Run an Artisan, Composer or Node.js command in the background; its
     * output streams to the command's run log.
     */
    public function runCommand(LaravelApp $app, string $tool, string $command, ?User $user = null): LaravelCommand
    {
        $command = $app->commands()->create([
            'user_id' => $user?->id,
            'tool' => $tool,
            'command' => $command,
            'run' => 'cmd-'.Str::lower(Str::random(12)),
            'status' => 'queued',
        ]);
        RunLaravelCommand::dispatch($command);

        return $command;
    }

    /**
     * Apply the application's queue workers, scheduler and processes on the
     * server; with $web, its Nginx configuration too.
     */
    public function syncProcesses(LaravelApp $app, bool $web = false): void
    {
        $app->update(['processes_state' => 'syncing', 'processes_error' => null]);
        SyncLaravelProcesses::dispatch($app, $web);
    }

    /**
     * Remove the application from its site. The site serves public_html
     * again; the code under laravel/ goes when $removeFiles.
     */
    public function remove(LaravelApp $app, bool $removeFiles): void
    {
        $app->update(['status' => LaravelAppStatus::Removing]);
        RemoveLaravelApp::dispatch($app, $removeFiles);
    }

    /**
     * Point the site's web server at $webRoot (null for public_html).
     */
    public function serveFrom(Site $site, ?string $webRoot): void
    {
        if ($site->web_root === $webRoot) {
            return;
        }
        $site->update(['web_root' => $webRoot]);
        ProvisionSite::dispatch($site);
    }

    /**
     * Values written into the first .env, on top of .env.example.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    public function installEnv(Site $site, array $options): array
    {
        $env = [
            'APP_NAME' => $site->domain,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => ($site->hasHttps() ? 'https://' : 'http://').$site->primaryHost(),
            // Daily files, kept two weeks; the Günlükler page lists them.
            'LOG_STACK' => 'daily',
            'LOG_DAILY_DAYS' => '14',
        ];
        $base = '/home/'.$site->systemUser->name.'/web/'.$site->domain.'/laravel';

        return $env + match ($options['database']) {
            // In shared/, so every release uses the same file.
            'sqlite' => ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $base.'/shared/database/database.sqlite'],
            'existing' => [
                'DB_CONNECTION' => $options['db']['driver'],
                'DB_HOST' => $options['db']['host'],
                'DB_PORT' => $options['db']['port'],
                'DB_DATABASE' => $options['db']['database'],
                'DB_USERNAME' => $options['db']['username'],
                'DB_PASSWORD' => $options['db']['password'],
            ],
            // Without a database, sessions, cache and jobs must not need one.
            default => ['SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync'],
        };
    }

    private function newDeployment(LaravelApp $app, string $kind, ?string $ref, ?User $user, bool $migrate, bool $frontend, ?string $triggeredBy = null): LaravelDeployment
    {
        return LaravelDeployment::create([
            'laravel_app_id' => $app->id,
            'number' => $app->nextNumber(),
            'kind' => $kind,
            'status' => DeploymentStatus::Queued,
            'ref' => $ref,
            'triggered_by' => $triggeredBy ?? $user?->name,
            'run' => $kind.'-'.Str::lower(Str::random(12)),
            'migrate' => $migrate,
            'frontend' => $frontend,
        ]);
    }
}
