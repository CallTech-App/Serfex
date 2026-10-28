<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Enums\WordPressOperationStatus;
use App\Jobs\RunWordPressOperation;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\Site;
use App\Models\User;
use App\Models\WordPressInstall;
use App\Models\WordPressOperation;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use App\Support\ReadOnlyGuard;
use Illuminate\Support\Str;

/**
 * WordPress araç seti: WordPress on a site, run by the agent through
 * WP-CLI as the site's account. One WordPress per site is managed: one
 * Serfex installs into the web root, or one found on the site.
 *
 * Installs, updates and returns to a restore point run as queued
 * operations with a live log; the rest (sign-in links, maintenance mode,
 * plugin switches, hardening) answer at once.
 */
class WordPressSites
{
    /** Hardening rules, in the agent's order; the recommended ones first. */
    public const RULES = ['file_edit', 'config_perms', 'uploads_php', 'sensitive_files', 'xmlrpc'];

    public const RECOMMENDED = ['file_edit', 'config_perms', 'uploads_php', 'sensitive_files'];

    /** Rules that live in the site's Nginx snippet. */
    public const NGINX_RULES = ['uploads_php', 'sensitive_files', 'xmlrpc'];

    /** Core auto-update policies (WP_AUTO_UPDATE_CORE). */
    public const AUTO_UPDATES = ['minor', 'all', 'off'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Why WordPress cannot be installed on the site now, or null.
     */
    public function installRefusal(Site $site): ?string
    {
        return match (true) {
            $site->laravelApp()->exists() => __('Bu sitede bir Laravel uygulaması var; WordPress ayrı bir siteye kurulmalı.'),
            $site->isSuspended() => __('Site askıda.'),
            $site->status !== SiteStatus::Active => __('Site henüz hazır değil.'),
            ! app(DatabaseServers::class)->isInstalled(DatabaseServers::MYSQL) => __('WordPress için MySQL ya da MariaDB gerekir; önce Veritabanları\'ndan kurun.'),
            default => app(Quotas::class)->refusal($site->customer(), 'databases'),
        };
    }

    /**
     * WordPress installations found on the site, from the agent.
     *
     * @return list<array{path: string, version: string}>
     */
    public function scan(Site $site): array
    {
        $found = $this->agent->call('wordpress.scan', [
            'domain' => $site->domain,
            'user' => $site->systemUser->name,
            'php_version' => $site->php_version,
            'root' => $site->web_root ?? 'public_html',
        ], config('serfex.agent.job_timeout')) ?? [];

        return array_values(array_map(fn (array $f) => ['path' => (string) $f['path'], 'version' => (string) ($f['version'] ?? '')], $found));
    }

    /**
     * Manages a WordPress found on the site.
     */
    public function adopt(Site $site, string $path): WordPressInstall
    {
        $install = WordPressInstall::create(['site_id' => $site->id, 'path' => $path, 'status' => WordPressInstall::ACTIVE]);
        $install->setRelation('site', $site);
        $this->refresh($install);

        return $install;
    }

    /**
     * A database name free on the server: kahve_wp, kahve_wp2…
     */
    public function databaseName(Site $site): string
    {
        $prefix = Databases::prefix($site->systemUser);
        for ($i = 1; ; $i++) {
            $name = $prefix.'wp'.($i > 1 ? $i : '');
            $user = Str::limit($name, Databases::MAX_USER, '');
            if (! Database::withTrashed()->where('server', DatabaseServers::MYSQL)->where('name', $name)->exists()
                && ! DatabaseUser::withTrashed()->where('server', DatabaseServers::MYSQL)->where('name', $user)->exists()) {
                return $name;
            }
        }
    }

    /**
     * Creates the database and starts the install. The passwords travel
     * only in the encrypted job.
     *
     * @param  array{title: string, admin_user: string, admin_email: string, admin_password: string, locale: string, harden: bool}  $data
     *
     * @throws AgentException when the database cannot be created
     */
    public function install(Site $site, array $data, ?User $by): WordPressInstall
    {
        $name = $this->databaseName($site);
        $password = Databases::password();
        $user = Str::limit($name, Databases::MAX_USER, '');
        $database = app(Databases::class)->create($site, DatabaseServers::MYSQL, $name, $user, $password);

        WordPressInstall::where('site_id', $site->id)->where('status', WordPressInstall::FAILED)->delete();
        $install = WordPressInstall::create([
            'site_id' => $site->id,
            'path' => $site->web_root ?? 'public_html',
            'status' => WordPressInstall::INSTALLING,
            'database_id' => $database->id,
        ]);
        $operation = $this->operation($install, 'install', $by, ['locale' => $data['locale'], 'harden' => $data['harden']]);
        RunWordPressOperation::dispatch($operation, [
            'url' => ($site->hasHttps() ? 'https' : 'http').'://'.$site->domain,
            'title' => $data['title'],
            'admin_user' => $data['admin_user'],
            'admin_email' => $data['admin_email'],
            'admin_password' => $data['admin_password'],
            'locale' => $data['locale'],
            'harden' => $data['harden'],
            'apache' => $site->web_server === Site::APACHE,
            'db' => ['name' => $name, 'user' => $user, 'password' => $password, 'host' => 'localhost'],
        ]);

        return $install;
    }

    /**
     * Starts an update of WordPress itself and plugins and themes by name
     * (['*'] for all with an update).
     *
     * @param  list<string>  $plugins
     * @param  list<string>  $themes
     */
    public function update(WordPressInstall $install, bool $core, array $plugins, array $themes, ?User $by): WordPressOperation
    {
        $operation = $this->operation($install, 'update', $by, ['core' => $core, 'plugins' => array_values($plugins), 'themes' => array_values($themes)]);
        RunWordPressOperation::dispatch($operation);

        return $operation;
    }

    /**
     * Puts the restore point of an update back.
     */
    public function restore(WordPressInstall $install, WordPressOperation $update, ?User $by): WordPressOperation
    {
        $operation = $this->operation($install, 'restore', $by, ['operation' => $update->id]);
        $operation->update(['point' => $update->point]);
        RunWordPressOperation::dispatch($operation);

        return $operation;
    }

    private function operation(WordPressInstall $install, string $kind, ?User $by, array $request): WordPressOperation
    {
        return WordPressOperation::create([
            'wordpress_install_id' => $install->id,
            'kind' => $kind,
            'run' => 'wp-'.$kind.'-'.Str::lower(Str::random(10)),
            'request' => $request,
            'user_id' => $by?->id,
            'triggered_by' => $by?->name,
        ]);
    }

    /**
     * Reads the installation from the agent and keeps the answer. Returns
     * the error when the agent could not read it.
     */
    public function refresh(WordPressInstall $install): ?string
    {
        try {
            $state = (array) $this->agent->call('wordpress.status', $install->target(), config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            return $e->reason ?: $e->getMessage();
        }
        $this->keep($install, $state);

        return null;
    }

    /**
     * Stores a wordpress.status answer.
     *
     * @param  array<string, mixed>  $state
     */
    public function keep(WordPressInstall $install, array $state): void
    {
        $updates = ($state['core_update'] ?? '') !== '' ? 1 : 0;
        foreach (['plugins', 'themes'] as $kind) {
            foreach ((array) ($state[$kind] ?? []) as $item) {
                $updates += ($item['update'] ?? '') !== '' ? 1 : 0;
            }
        }
        $values = [
            'version' => ($state['version'] ?? '') ?: null,
            'core_update' => ($state['core_update'] ?? '') ?: null,
            'update_count' => $updates,
            'maintenance' => (bool) ($state['maintenance'] ?? false),
            'problem' => ($state['problem'] ?? '') ?: null,
            'state' => $state,
            'checked_at' => now(),
        ];
        // A read-only administrator sees the answer without it being kept.
        $install->forceFill($values);
        if (! ReadOnlyGuard::active()) {
            $install->save();
        }
    }

    /**
     * A one-time sign-in link to the dashboard, valid for two minutes.
     */
    public function loginUrl(WordPressInstall $install): string
    {
        return (string) $this->agent->call('wordpress.login', $install->target(), config('serfex.agent.job_timeout'))['url'];
    }

    public function maintenance(WordPressInstall $install, bool $on): void
    {
        $this->agent->call('wordpress.maintenance', $install->target() + ['on' => $on], config('serfex.agent.job_timeout'));
        $install->update(['maintenance' => $on, 'state' => ['maintenance' => $on] + (array) $install->state]);
    }

    /**
     * Activates or deactivates a plugin, activates a theme or switches its
     * automatic updates; the changed item comes back.
     *
     * @return array<string, mixed>
     */
    public function item(WordPressInstall $install, string $kind, string $name, string $action): array
    {
        $item = (array) $this->agent->call('wordpress.item', $install->target() + ['kind' => $kind, 'name' => $name, 'action' => $action], config('serfex.agent.job_timeout'));
        $this->refresh($install);

        return $item;
    }

    public function autoUpdate(WordPressInstall $install, string $policy): void
    {
        $this->agent->call('wordpress.autoupdate', $install->target() + ['core' => $policy], config('serfex.agent.job_timeout'));
        $install->update(['state' => ['auto_update_core' => $policy] + (array) $install->state]);
    }

    /**
     * Turns hardening rules on or off and keeps the new state.
     *
     * @param  array<string, bool>  $rules
     */
    public function harden(WordPressInstall $install, array $rules): void
    {
        $state = (array) $this->agent->call('wordpress.harden', $install->target() + ['web_path' => $install->webPath() ?? '/', 'rules' => $rules], config('serfex.agent.job_timeout'));
        $install->update(['state' => ['hardening' => (array) ($state['hardening'] ?? [])] + (array) $install->state]);
    }

    /**
     * Stops managing the installation; WordPress stays on the site.
     */
    public function detach(WordPressInstall $install): void
    {
        $this->agent->call('wordpress.detach', $install->target(), config('serfex.agent.job_timeout'));
        $install->delete();
    }

    /**
     * The log of an operation from $offset on, for <x-run-log>.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    public function read(WordPressInstall $install, string $run, int $offset = 0): ?array
    {
        $operation = $install->operations()->where('run', $run)->first();
        if ($operation === null) {
            return null;
        }
        try {
            $chunk = $this->agent->call('wordpress.read', ['domain' => $install->site->domain, 'run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => false, 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $operation->status->isBusy(),
        ];
    }

    /**
     * The update whose restore point "Geri al" puts back: the newest
     * update, while its point is kept and nothing ran after it.
     */
    public function restorable(WordPressInstall $install): ?WordPressOperation
    {
        $last = $install->operations()->whereIn('kind', ['update', 'restore'])
            ->whereNotIn('status', [WordPressOperationStatus::Failed, WordPressOperationStatus::RolledBack])->first();

        return $last?->canRestore() ? $last : null;
    }
}
