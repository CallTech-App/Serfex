<?php

namespace App\Services\Components;

use App\Jobs\RunComponentOperation;
use App\Models\ComponentOperation;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\PanelUpdate;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Backups\Backups;
use App\Services\Databases\DatabaseServers;
use App\Services\Mail\MailSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Optional server software (Yazılım Yönetimi): what can be installed, what
 * is installed and what uses it. The agent installs, updates and removes;
 * the panel adds what only it knows uses a component (sites on Apache or
 * on a PHP version, databases, Laravel applications on Redis) and refuses
 * to remove a component that is in use.
 *
 * Serfex's own Nginx, PHP and PostgreSQL are separate (serfex-web,
 * serfex-postgresql) and never listed here.
 */
class Components
{
    public const TABS = [
        'web' => 'Web sunucusu',
        'databases' => 'Veritabanı sunucuları',
        'other' => 'Diğer bileşenler',
        // Ubuntu's own packages (SystemPackages).
        'packages' => 'Ubuntu güncellemeleri',
    ];

    /** PHP versions and Node.js lines the agent offers. */
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1'];

    public const NODE_LINES = ['26', '24', '22'];

    /** Node.js lines with long-term support. */
    public const NODE_LTS = ['22', '24'];

    /** id => [name, description, tab, group] */
    private const CATALOG = [
        'nginx' => ['Nginx', 'Tüm sitelerin önünde durur, statik dosyaları sunar', 'web', 'Web sunucusu'],
        'apache' => ['Apache', '.htaccess kullanan siteler için, Nginx arkasında çalışır', 'web', 'Web sunucusu'],
        'mysql' => ['MySQL', 'Varsayılan veritabanı sunucusu', 'databases', null],
        'mariadb' => ['MariaDB', 'MySQL ile uyumlu alternatif', 'databases', null],
        'postgresql' => ['PostgreSQL', 'MySQL ile birlikte kurulabilir; Laravel ve Django için', 'databases', null],
        'redis' => ['Redis', 'Laravel önbelleği, oturumları ve kuyrukları; her uygulamaya ayrı örnek', 'other', 'Önbellek ve kuyruk'],
        'memcached' => ['Memcached', 'Basit nesne önbelleği, PHP eklentisiyle', 'other', 'Önbellek ve kuyruk'],
        'supervisor' => ['Supervisor', 'Kendi süreçlerinizi çalışır tutar (Laravel işçileri systemd ile çalışır)', 'other', 'Önbellek ve kuyruk'],
        'composer' => ['Composer', 'PHP paket yöneticisi', 'other', 'Çalışma ortamları'],
        'python' => ['Python 3', 'pip, venv ve derleme başlıkları', 'other', 'Çalışma ortamları'],
        'roundcube' => ['Roundcube', 'Varsayılan webmail, Ubuntu paketi', 'other', 'E-posta'],
        'snappymail' => ['SnappyMail', 'Hafif ve hızlı webmail, GitHub sürümü', 'other', 'E-posta'],
        'fail2ban' => ['Fail2ban', 'SSH ve posta girişlerine kaba kuvvet saldırılarını engeller', 'other', 'Güvenlik'],
        'modsecurity' => ['ModSecurity', 'Web uygulaması güvenlik duvarı (OWASP CRS kuralları)', 'other', 'Güvenlik'],
        'clamav' => ['ClamAV', 'Gelen ve giden postada virüs taraması', 'other', 'Güvenlik'],
    ];

    /** @var array<string, array<string, mixed>>|null component.list by id */
    private ?array $list = null;

    private ?string $checkedAt = null;

    /** Set while the agent upgrades Ubuntu's packages. */
    private ?string $packagesBusy = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly DatabaseServers $databases,
        private readonly MailSettings $mail,
    ) {}

    /**
     * Every component the agent knows, by id; null when the agent cannot
     * be reached. With $error the reason is set.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function statuses(?string &$error = null): ?array
    {
        if ($this->list !== null) {
            return $this->list;
        }
        try {
            $list = (array) $this->agent->call('component.list', [], 60);
        } catch (AgentException $e) {
            $error = $e instanceof AgentUnavailableException ? null : $e->reason;

            return null;
        }
        $this->checkedAt = $list['checked_at'] ?? null;
        $this->packagesBusy = $list['packages_busy'] ?? null;
        $this->list = [];
        foreach ($list['components'] ?? [] as $c) {
            $this->list[$c['id']] = $c;
        }
        $installed = array_keys(array_filter($this->list, fn ($c) => $c['installed'] ?? false));
        sort($installed);
        if ($installed !== $this->installed()) {
            Setting::put('components.installed', $installed);
        }
        $this->settleAll();

        return $this->list;
    }

    /**
     * Closes the operations whose job is gone: a worker restarted or
     * killed while it waited on the agent (a package upgrade of the very
     * install restarting it, for one) never records the outcome. The
     * agent no longer works on them, so their run log has the last word.
     */
    private function settleAll(): void
    {
        foreach (ComponentOperation::running()->get() as $op) {
            $busy = $op->component === SystemPackages::COMPONENT ? $this->packagesBusy : ($this->list[$op->component]['busy'] ?? null);
            if ($busy !== null) {
                continue;
            }
            // A job sets "running" just before it calls the agent, and a
            // queued one may wait behind a long job.
            $since = $op->status === 'running' ? now()->subMinutes(2) : now()->subHour();
            if ($op->updated_at !== null && $op->updated_at->gt($since)) {
                continue;
            }
            $this->settle($op, __('İşlem yarıda kaldı.'));
        }
    }

    /**
     * Records an operation's outcome from the last line of its run log:
     * "✓" succeeded, "✗" failed with that reason; without either it failed
     * with $reason. Returns false when the agent cannot be reached.
     */
    public function settle(ComponentOperation $op, string $reason): bool
    {
        try {
            $chunk = (array) $this->agent->call('component.run.read', ['run' => $op->run, 'offset' => 0]);
            $size = (int) ($chunk['size'] ?? 0);
            if ((int) ($chunk['offset'] ?? 0) < $size) {
                $chunk = (array) $this->agent->call('component.run.read', ['run' => $op->run, 'offset' => max(0, $size - 4096)]);
            }
        } catch (AgentException) {
            return false;
        }
        $lines = preg_split('/\R/', trim((string) ($chunk['data'] ?? '')));
        $last = (string) end($lines);
        if (str_starts_with($last, '✓')) {
            $op->update(['status' => 'succeeded', 'error' => null, 'finished_at' => now()]);
        } else {
            $error = str_starts_with($last, '✗') ? trim(Str::after($last, '✗')) : $reason;
            $op->update(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
        }
        $this->recorded($op);

        return true;
    }

    /**
     * Brings what the other pages read up to date after an operation:
     * an install of a database server here counts as the database setup.
     */
    public function recorded(ComponentOperation $op): void
    {
        if ($op->component === SystemPackages::COMPONENT) {
            // Genel Bakış counts again; upgraded database servers were
            // checked by the agent.
            Cache::forget(SystemPackages::SUMMARY_KEY);
            $this->databases->status();

            return;
        }
        if (! in_array($op->component, ['mysql', 'mariadb', 'postgresql'], true)) {
            return;
        }
        $this->databases->status();
        if ($this->databases->products() !== [] && ($this->databases->setup()['state'] ?? null) !== 'installing') {
            $this->databases->saveSetup(['state' => 'ready', 'step' => null, 'error' => null, 'setup_at' => $this->databases->setup()['setup_at'] ?? now()->toIso8601String()]);
        }
    }

    /**
     * When the package lists were last checked for updates.
     */
    public function checkedAt(): ?string
    {
        return $this->checkedAt;
    }

    /**
     * The web server stack new sites get: nginx, or apache while Apache is
     * installed.
     */
    public function defaultStack(): string
    {
        $stack = Setting::get('web.default_stack', Site::NGINX);

        return $stack === Site::APACHE && $this->isInstalled('apache') ? Site::APACHE : Site::NGINX;
    }

    /**
     * Installed components as last seen by statuses().
     *
     * @return list<string>
     */
    public function installed(): array
    {
        return Setting::get('components.installed', []);
    }

    public function isInstalled(string $id): bool
    {
        return in_array($id, $this->installed(), true);
    }

    /**
     * The rows of a tab: groups of components with what the page shows
     * for each. Null when the agent cannot be reached.
     *
     * @return array<string, list<array<string, mixed>>>|null group => rows
     */
    public function rows(string $tab, ?string &$error = null): ?array
    {
        $statuses = $this->statuses($error);
        if ($statuses === null) {
            return null;
        }
        $running = ComponentOperation::running()->latest('id')->get()->keyBy('component');
        $groups = [];
        foreach ($this->ids() as $id) {
            [$name, , $inTab, $group] = $this->meta($id);
            if ($inTab !== $tab) {
                continue;
            }
            $groups[$group ?? ''][] = $this->row($id, $statuses[$id] ?? null, $running->get($id), $statuses);
        }

        return $groups;
    }

    /**
     * Ids in the order the page lists them.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = ['nginx', 'apache'];
        foreach (self::PHP_VERSIONS as $v) {
            $ids[] = 'php'.$v;
        }
        array_push($ids, 'mysql', 'mariadb', 'postgresql', 'redis', 'memcached', 'supervisor');
        foreach (self::NODE_LINES as $line) {
            $ids[] = 'node'.$line;
        }

        return [...$ids, 'composer', 'python', 'roundcube', 'snappymail', 'fail2ban', 'modsecurity', 'clamav'];
    }

    /**
     * [name, description, tab, group] of a component.
     *
     * @return array{0: string, 1: string, 2: string, 3: ?string}
     */
    public function meta(string $id): array
    {
        if (preg_match('/^php(\d\.\d)$/', $id, $m)) {
            // Listed on Hizmetler → PHP sürümleri.
            return ['PHP '.$m[1], __('PHP-FPM ve yaygın eklentiler (mysql, pgsql, intl, gd, redis...)'), 'php', null];
        }
        if (preg_match('/^node(\d+)$/', $id, $m)) {
            $lts = in_array($m[1], self::NODE_LTS, true);

            return ['Node.js '.$m[1].($lts ? ' LTS' : ''), $lts ? __('Vite derlemeleri ve Node uygulamaları') : __('En yeni sürüm hattı'), 'other', __('Çalışma ortamları')];
        }

        return self::CATALOG[$id] ?? [$id, '', 'other', null];
    }

    public function name(string $id): string
    {
        return $this->meta($id)[0];
    }

    /**
     * What an operation's progress window calls it.
     */
    public function operationName(ComponentOperation $operation): string
    {
        if ($operation->component === SystemPackages::COMPONENT) {
            $count = count($operation->packages ?? []);

            return $count === 1 ? $operation->packages[0] : ($count > 1 ? trans_choice(':count Ubuntu paketi', $count) : __('Ubuntu paketleri'));
        }
        $name = $this->name($operation->component);

        if ($operation->detail && ! str_starts_with($operation->action, 'ext-')) {
            // A database server's chosen version, the version it is
            // upgraded to, or the old version an upgrade kept.
            return self::releaseName($operation->component, $operation->detail);
        }

        return $operation->detail ? __(':name · :detail eklentisi', ['name' => $name, 'detail' => $operation->detail]) : $name;
    }

    /**
     * Why no install, update or removal can start now: the one operation
     * that runs at a time (with its id, for its progress), a database
     * server setup or a Serfex update. Null when nothing runs.
     *
     * @return array{text: string, operation: ?int}|null
     */
    public function blocker(): ?array
    {
        $wait = __('Bitene kadar başka kurulum, güncelleme veya kaldırma başlatılamaz.');
        $running = ComponentOperation::running()->latest('id')->first();
        if ($running !== null) {
            $name = $this->operationName($running);
            $doing = match ($running->action) {
                'update', 'packages' => __(':name güncelleniyor.', ['name' => $name]),
                'upgrade' => __(':name sürümüne yükseltiliyor.', ['name' => $name]),
                'remove', 'ext-remove', 'remove-old' => __(':name kaldırılıyor.', ['name' => $name]),
                default => __(':name kuruluyor.', ['name' => $name]),
            };

            return ['text' => $doing.' '.__(':since başladı.', ['since' => $running->created_at->diffForHumans()]).' '.$wait, 'operation' => $running->id];
        }
        if ($this->databaseSetupRunning()) {
            return ['text' => __('Veritabanı sunucusu kuruluyor.').' '.$wait, 'operation' => null];
        }
        if (PanelUpdate::running()->exists()) {
            return ['text' => __('Serfex güncelleniyor.').' '.$wait, 'operation' => null];
        }

        return null;
    }

    /**
     * Components the agent installs and removes itself (the others are
     * part of another setup, such as the webmail of the mail server).
     */
    public function operable(string $id): bool
    {
        return in_array($id, $this->ids(), true) && ! in_array($id, ['roundcube', 'snappymail'], true);
    }

    /**
     * What uses a component, as the panel knows it: sites on Apache or on
     * a PHP version, databases, Laravel applications.
     *
     * @return list<string>
     */
    public function users(string $id): array
    {
        if (preg_match('/^php(\d\.\d)$/', $id, $m)) {
            return Site::where('php_version', $m[1])->orderBy('domain')->pluck('domain')->all();
        }
        if (preg_match('/^node(\d+)$/', $id, $m)) {
            // Builds use the default line; it stays while it is the only one.
            $status = $this->statuses()[$id] ?? [];
            $others = collect($this->statuses() ?? [])->filter(fn ($c, $k) => str_starts_with($k, 'node') && $k !== $id && ($c['installed'] ?? false));
            if (! ($status['default'] ?? false) || $others->isNotEmpty()) {
                return [];
            }

            return $this->laravelSites(LaravelApp::where('frontend', true));
        }

        return match ($id) {
            'apache' => Site::where('web_server', Site::APACHE)->orderBy('domain')->pluck('domain')->all(),
            'mysql', 'mariadb' => $this->databaseUsers(DatabaseServers::MYSQL, $id),
            'postgresql' => $this->databaseUsers(DatabaseServers::POSTGRESQL, $id),
            'redis' => $this->laravelSites(LaravelApp::whereNotNull('redis_port')),
            'composer' => $this->laravelSites(LaravelApp::query()),
            default => [],
        };
    }

    /**
     * Why a component cannot be removed, or null when it can.
     *
     * @param  array<string, mixed>|null  $status
     */
    public function removalRefusal(string $id, ?array $status = null): ?string
    {
        $status ??= $this->statuses()[$id] ?? [];
        if ($status['required'] ?? null) {
            return __('Gerekli. Serfex tüm sitelerde Nginx kullanır.');
        }
        if ($kept = $status['kept'] ?? null) {
            return __('Yükseltmeden kalan :name önce kaldırılmalı.', ['name' => self::releaseName($id, (string) $kept['from'])]);
        }
        $used = array_values(array_unique([...$this->users($id), ...($status['used_by'] ?? [])]));
        if ($used === []) {
            return null;
        }
        $list = count($used) > 2 ? __(':used ve :used2 diğeri', ['used' => implode(', ', array_slice($used, 0, 2)), 'used2' => (count($used) - 2)]) : implode(', ', $used);
        $hint = match (true) {
            $id === 'apache' => __('Önce bu siteleri PHP ve Web Sunucusu sayfasından Nginx + PHP-FPM\'e alın.'),
            str_starts_with($id, 'php') => __('Önce bu siteleri başka bir PHP sürümüne alın.'),
            in_array($id, ['mysql', 'mariadb', 'postgresql'], true) => __('Önce veritabanlarını ve kullanıcılarını silin.'),
            $id === 'redis' => __('Önce uygulamaların Redis bağlantısını kaldırın.'),
            $id === 'composer' => __('Laravel dağıtımları için gerekli.'),
            str_starts_with($id, 'node') => __('Laravel derlemeleri bu sürümü kullanıyor; önce başka bir Node.js sürümü kurun.'),
            $id === 'supervisor' => __('Supervisor\'da tanımlı programlar var.'),
            default => '',
        };

        return trim(__('Kullanılıyor: :list. :hint', ['list' => $list, 'hint' => $hint]));
    }

    /**
     * Starts installing, updating or removing a component in the
     * background; for a database server also upgrading it to a newer
     * major version ($version, the one offered) and removing the old
     * version an upgrade kept. Returns the operation, or the reason it
     * cannot start.
     */
    public function start(string $id, string $action, ?User $user, ?string $version = null): ComponentOperation|string
    {
        $database = in_array($id, ['mysql', 'mariadb', 'postgresql'], true);
        if (! $this->operable($id) || ! in_array($action, ['install', 'update', 'remove', ...($database ? ['upgrade', 'remove-old'] : [])], true)) {
            return __('Bu bileşen buradan yönetilemiyor.');
        }
        // Read first: it also closes operations whose job is gone.
        $statuses = $this->statuses($error);
        if (ComponentOperation::running()->exists() || $this->databaseSetupRunning()) {
            return __('Başka bir kurulum sürüyor. Bitince yeniden deneyin.');
        }
        if (PanelUpdate::running()->exists()) {
            return __('Serfex güncelleniyor. Bitince yeniden deneyin.');
        }
        if ($statuses === null) {
            return $error ? __('Sunucu ajanı hata verdi: :error', ['error' => $error]) : __('Sunucu ajanına ulaşılamıyor.');
        }
        $status = $statuses[$id] ?? null;
        if ($status === null) {
            return __('Bu sunucunun ajanı bu bileşeni tanımıyor.');
        }
        if ($action === 'remove' && ($refusal = $this->removalRefusal($id, $status)) !== null) {
            return $refusal;
        }
        if ($action === 'install' && ($refusal = $this->installRefusal($id, $statuses)) !== null) {
            return $refusal;
        }
        if ($action !== 'install' && ! ($status['installed'] ?? false)) {
            return __(':name kurulu değil.', ['name' => $this->name($id)]);
        }
        if ($action === 'upgrade' && ($refusal = $this->upgradeRefusal($id, $status, $version)) !== null) {
            return $refusal;
        }
        if ($action === 'remove-old') {
            if (! isset($status['kept']['from'])) {
                return __('Yükseltmeden kalan eski sürüm yok.');
            }
            $version = (string) $status['kept']['from'];
        } elseif ($version !== null && ! in_array($action, ['install', 'upgrade'], true)) {
            return __(':name için bu sürüm sunulmuyor.', ['name' => $this->name($id)]);
        } elseif ($version !== null && $action === 'install' && ! in_array($version, array_column($status['choices'] ?? [], 'major'), true)) {
            return __(':name için bu sürüm sunulmuyor.', ['name' => $this->name($id)]);
        }

        $operation = ComponentOperation::create([
            'component' => $id,
            'action' => $action,
            'detail' => $version,
            'run' => 'c'.Str::lower(Str::random(23)),
            'user_id' => $user?->id,
        ]);
        RunComponentOperation::dispatch($operation);

        return $operation;
    }

    /**
     * Why a database server cannot be upgraded to $version now, or null.
     * Only the version the agent offers (the next allowed major) is
     * taken; a backup or restore must not run at the same time.
     *
     * @param  array<string, mixed>  $status
     */
    public function upgradeRefusal(string $id, array $status, ?string $version): ?string
    {
        $offer = $status['upgrade'] ?? null;
        if (isset($status['kept']['from'])) {
            return __('Önceki yükseltmeden kalan :name duruyor; yeniden yükseltmeden önce onu kaldırın.', ['name' => self::releaseName($id, (string) $status['kept']['from'])]);
        }
        if (! isset($offer['to']) || $version !== (string) $offer['to']) {
            return __(':name için bu sürüme yükseltme sunulmuyor.', ['name' => $this->name($id)]);
        }
        if (! ($status['running'] ?? false)) {
            return __(':name çalışmıyor. Önce Hizmetler sayfasından başlatın; yükseltme yalnızca çalışan bir sunucuda yapılır.', ['name' => $this->name($id)]);
        }
        if (app(Backups::class)->busy()) {
            return __('Bir yedekleme ya da geri yükleme sürüyor. Bitince yeniden deneyin.');
        }

        return null;
    }

    /**
     * A package version without its epoch and build suffix:
     * "8.4.13-1+ubuntu24.04.1+deb.sury.org+1" is "8.4.13-1".
     */
    public static function shortVersion(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        return preg_replace(['/^\d+:/', '/\+.*$/', '/^v(?=\d)/'], '', $version);
    }

    /**
     * Where an install of a component comes from, for people: the agent
     * names the apt archive as host and path. Node.js, Composer and
     * PostgreSQL are known before the agent has looked.
     */
    public static function source(string $id, ?string $from): ?string
    {
        if ($from === null || $from === '') {
            return match (true) {
                str_starts_with($id, 'node') => 'nodejs.org',
                $id === 'composer' => 'getcomposer.org',
                $id === 'postgresql' => 'apt.postgresql.org',
                $id === 'mysql' => __('MySQL deposu (repo.mysql.com)'),
                $id === 'mariadb' => __('MariaDB deposu (dlm.mariadb.com)'),
                default => null,
            };
        }

        return match (true) {
            str_ends_with(Str::before($from, '/'), 'ubuntu.com') => __('Ubuntu deposu'),
            str_contains($from, '/ondrej/php') => 'ppa:ondrej/php',
            str_starts_with($from, 'repo.mysql.com') => __('MySQL deposu (repo.mysql.com)'),
            str_starts_with($from, 'dlm.mariadb.com') => __('MariaDB deposu (dlm.mariadb.com)'),
            default => Str::before($from, '/'),
        };
    }

    /**
     * "MySQL 8.4 LTS", "PostgreSQL 18": a database server's major version.
     */
    public static function releaseName(string $id, string $major): string
    {
        return match ($id) {
            'mysql' => "MySQL {$major} LTS",
            'mariadb' => "MariaDB {$major} LTS",
            'postgresql' => "PostgreSQL {$major}",
            default => $major,
        };
    }

    /**
     * The major versions a database server can be installed in, as the
     * agent reports them (Serfex decides which; the default first).
     *
     * @param  list<array<string, mixed>>  $choices
     * @return list<array{major: string, name: string, version: ?string, source: ?string, default: bool}>
     */
    public static function choices(string $id, array $choices): array
    {
        return array_values(array_map(fn (array $c) => [
            'major' => (string) $c['major'],
            'name' => self::releaseName($id, (string) $c['major']),
            'version' => self::shortVersion($c['version'] ?? null),
            'source' => self::source($id, $c['from'] ?? null),
            'default' => (bool) ($c['default'] ?? false),
        ], array_filter($choices, fn ($c) => isset($c['major']))));
    }

    /**
     * Version and source an install of a component would bring, for the
     * confirmations; null when it is installed or the agent cannot be
     * reached. The version is null until the agent knows it (Node.js,
     * Composer and a PostgreSQL archive apt does not list yet are looked
     * up by "Güncellemeleri denetle" and every night).
     *
     * @return array{version: ?string, source: ?string, choices: list<array{major: string, name: string, version: ?string, source: ?string, default: bool}>}|null
     */
    public function candidate(string $id): ?array
    {
        $status = $this->statuses()[$id] ?? null;
        if ($status === null || ($status['installed'] ?? false)) {
            return null;
        }

        return [
            'version' => self::shortVersion($status['candidate'] ?? null),
            'source' => self::source($id, $status['candidate_from'] ?? null),
            'choices' => self::choices($id, $status['choices'] ?? []),
        ];
    }

    /**
     * Why a component cannot be installed now, or null.
     *
     * @param  array<string, array<string, mixed>>  $statuses
     */
    public function installRefusal(string $id, array $statuses): ?string
    {
        $other = ['mysql' => 'mariadb', 'mariadb' => 'mysql'][$id] ?? null;
        if ($other !== null && ($statuses[$other]['installed'] ?? false)) {
            return __(':name kurulu; :name2 onunla aynı anda kurulamaz.', ['name' => $this->name($other), 'name2' => $this->name($id)]);
        }

        return null;
    }

    /**
     * True while Veritabanları installs database servers, so both pages
     * never install at once.
     */
    public function databaseSetupRunning(): bool
    {
        return ($this->databases->setup()['state'] ?? null) === 'installing';
    }

    /**
     * @param  array<string, mixed>|null  $status
     * @param  array<string, array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    private function row(string $id, ?array $status, ?ComponentOperation $running, array $statuses): array
    {
        [$name, $description] = $this->meta($id);
        if ($id === 'roundcube' || $id === 'snappymail') {
            return $this->webmailRow($id, $name, $description);
        }
        $installed = (bool) ($status['installed'] ?? false);
        $busy = $running?->action ?? ($status['busy'] ?? null);
        if ($id === 'mysql' || $id === 'mariadb' || $id === 'postgresql') {
            $busy ??= $this->databaseSetupRunning() && in_array($id, $this->databases->setup()['products'] ?? [], true) ? 'install' : null;
        }
        $users = $installed ? $this->users($id) : [];

        return [
            'id' => $id,
            'name' => $name,
            'description' => $description,
            'known' => $status !== null,
            'installed' => $installed,
            'version' => self::shortVersion($status['version'] ?? null),
            'latest' => self::shortVersion($status['latest'] ?? null),
            'candidate' => $installed ? null : self::shortVersion($status['candidate'] ?? null),
            'source' => $installed ? null : self::source($id, $status['candidate_from'] ?? null),
            'choices' => $installed ? [] : self::choices($id, $status['choices'] ?? []),
            'upgrade' => $installed && isset($status['upgrade']['to']) ? ['from' => (string) $status['upgrade']['from'], 'to' => (string) $status['upgrade']['to'], 'name' => self::releaseName($id, (string) $status['upgrade']['to'])] : null,
            'kept' => $installed && isset($status['kept']['from']) ? ['from' => (string) $status['kept']['from'], 'name' => self::releaseName($id, (string) $status['kept']['from']), 'until' => $status['kept']['until'] ?? null] : null,
            'running' => (bool) ($status['running'] ?? false),
            'units' => $status['units'] ?? [],
            'busy' => $busy,
            'operation' => $running?->id,
            'default' => (bool) ($status['default'] ?? false),
            'settings' => $status['settings'] ?? null,
            'usage' => $installed ? $this->usage($id, $users, $status) : '—',
            'refusal' => $installed ? $this->removalRefusal($id, $status) : $this->installRefusal($id, $statuses),
        ];
    }

    /**
     * What the Kullanım column says.
     *
     * @param  list<string>  $users
     * @param  array<string, mixed>  $status
     */
    private function usage(string $id, array $users, array $status): string
    {
        $agent = $status['used_by'] ?? [];
        if ($id === 'nginx') {
            return __(':value site', ['value' => Site::count()]);
        }
        if ($id === 'apache' || str_starts_with($id, 'php')) {
            $n = $id === 'apache' ? count($users) : Site::where('php_version', substr($id, 3))->count();
            $extra = array_values(array_filter($agent, fn ($u) => ! str_contains($u, '.') || str_contains($u, ' ')));

            return implode(' · ', array_filter([$n ? __(':n site', ['n' => $n]) : null, ...$extra])) ?: __('Henüz kullanılmıyor');
        }
        if (in_array($id, ['mysql', 'mariadb', 'postgresql'], true)) {
            $server = $id === 'postgresql' ? DatabaseServers::POSTGRESQL : DatabaseServers::MYSQL;
            $dbs = Database::where('server', $server)->count();
            $sites = Database::where('server', $server)->whereNotNull('site_id')->distinct()->count('site_id');

            return $dbs ? __(':dbs veritabanı · :sites site', ['dbs' => $dbs, 'sites' => $sites]) : __('Henüz veritabanı yok');
        }
        if (str_starts_with($id, 'node')) {
            return ($status['default'] ?? false) ? __('Derlemelerde kullanılıyor') : __('Yedek sürüm');
        }

        return match ($id) {
            'redis' => $users ? __(':users Laravel uygulaması', ['users' => count($users)]) : __('Henüz kullanılmıyor'),
            'composer' => $users ? __(':users Laravel uygulaması', ['users' => count($users)]) : __('Komut satırında'),
            'supervisor' => $agent ? trans_choice(':count program', count($agent)) : __('Henüz kullanılmıyor'),
            'fail2ban' => $this->mail->isSetUp() ? __('SSH ve posta girişleri') : __('SSH girişleri'),
            'modsecurity' => ($status['settings']['mode'] ?? 'detect') === 'block' ? __('Engelleme kipinde · seviye :level', ['level' => $status['settings']['paranoia'] ?? 1]) : __('Yalnızca izleme · seviye :level', ['level' => $status['settings']['paranoia'] ?? 1]),
            'clamav' => __('Posta taraması (Rspamd)'),
            default => __('Kurulu'),
        };
    }

    /**
     * Roundcube and SnappyMail are the mail server's webmail: one of them
     * is served, chosen in E-posta ayarları or here.
     *
     * @return array<string, mixed>
     */
    private function webmailRow(string $id, string $name, string $description): array
    {
        $server = $this->mail->server();
        $served = $this->mail->servedWebmailApp();
        $installed = $this->mail->isSetUp() && $served === $id;
        $mailboxes = $installed ? Mailbox::count() : 0;
        $busy = null;
        if (($server['state'] ?? null) === 'installing') {
            $busy = match (true) {
                $this->mail->webmail() && $this->mail->webmailApp() === $id => $installed ? 'update' : 'install',
                $installed => 'remove',
                default => null,
            };
        }
        $snappy = $id === 'snappymail' && $installed;

        return [
            'id' => $id, 'name' => $name, 'description' => $description, 'known' => true,
            'version' => $snappy ? self::shortVersion($server['webmail_version'] ?? null) : null,
            'latest' => $snappy ? self::shortVersion($server['webmail_latest'] ?? null) : null,
            'candidate' => null, 'source' => null, 'choices' => [],
            'running' => true, 'units' => [], 'operation' => null, 'default' => false, 'settings' => null,
            'installed' => $installed,
            'busy' => $busy,
            'usage' => $installed ? ($mailboxes ? __(':mailboxes posta kutusu', ['mailboxes' => $mailboxes]) : __('Henüz posta kutusu yok')) : '—',
            'refusal' => $this->mail->isSetUp() ? null : __('E-posta sunucusu kurulunca gelir.'),
            'mail' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function databaseUsers(string $server, string $product): array
    {
        if (($this->databases->products()[$server] ?? null) !== $product) {
            return [];
        }
        $dbs = Database::where('server', $server)->orderBy('name')->pluck('name')->all();
        if ($dbs !== []) {
            return $dbs;
        }

        return DatabaseUser::where('server', $server)->orderBy('name')->pluck('name')->all();
    }

    /**
     * @param  Builder<LaravelApp>  $query
     * @return list<string>
     */
    private function laravelSites($query): array
    {
        return $query->with('site')->get()->map(fn (LaravelApp $a) => $a->site?->domain)->filter()->sort()->values()->all();
    }
}
