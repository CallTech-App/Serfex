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
use App\Services\Databases\DatabaseServers;
use App\Services\Mail\MailSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Optional server software (Yazılım Yönetimi): what can be installed, what
 * is installed and what uses it. The agent installs, updates and removes;
 * the panel adds what only it knows uses a component (sites on Apache or
 * on a PHP version, databases, Laravel applications on Redis) and refuses
 * to remove a component that is in use.
 *
 * Servik's own Nginx, PHP and PostgreSQL are separate (servik-web,
 * servik-postgresql) and never listed here.
 */
class Components
{
    public const TABS = [
        'web' => 'Web sunucusu',
        'veritabani' => 'Veritabanı sunucuları',
        'diger' => 'Diğer bileşenler',
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
        'mysql' => ['MySQL', 'Varsayılan veritabanı sunucusu', 'veritabani', null],
        'mariadb' => ['MariaDB', 'MySQL ile uyumlu alternatif', 'veritabani', null],
        'postgresql' => ['PostgreSQL', 'MySQL ile birlikte kurulabilir; Laravel ve Django için', 'veritabani', null],
        'redis' => ['Redis', 'Laravel önbelleği, oturumları ve kuyrukları; her uygulamaya ayrı örnek', 'diger', 'Önbellek ve kuyruk'],
        'memcached' => ['Memcached', 'Basit nesne önbelleği, PHP eklentisiyle', 'diger', 'Önbellek ve kuyruk'],
        'supervisor' => ['Supervisor', 'Kendi süreçlerinizi çalışır tutar (Laravel işçileri systemd ile çalışır)', 'diger', 'Önbellek ve kuyruk'],
        'composer' => ['Composer', 'PHP paket yöneticisi', 'diger', 'Çalışma ortamları'],
        'python' => ['Python 3', 'pip, venv ve derleme başlıkları', 'diger', 'Çalışma ortamları'],
        'roundcube' => ['Roundcube', 'Webmail, posta sunucusuyla birlikte', 'diger', 'E-posta'],
        'snappymail' => ['SnappyMail', 'Hafif webmail alternatifi', 'diger', 'E-posta'],
        'fail2ban' => ['Fail2ban', 'SSH ve posta girişlerine kaba kuvvet saldırılarını engeller', 'diger', 'Güvenlik'],
        'modsecurity' => ['ModSecurity', 'Web uygulaması güvenlik duvarı (OWASP CRS kuralları)', 'diger', 'Güvenlik'],
        'clamav' => ['ClamAV', 'Gelen ve giden postada virüs taraması', 'diger', 'Güvenlik'],
    ];

    /** @var array<string, array<string, mixed>>|null component.list by id */
    private ?array $list = null;

    private ?string $checkedAt = null;

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
            if (($this->list[$op->component]['busy'] ?? null) !== null) {
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

            return ['Node.js '.$m[1].($lts ? ' LTS' : ''), $lts ? __('Vite derlemeleri ve Node uygulamaları') : __('En yeni sürüm hattı'), 'diger', __('Çalışma ortamları')];
        }

        return self::CATALOG[$id] ?? [$id, '', 'diger', null];
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
        $name = $this->name($operation->component);

        return $operation->detail ? __(':name · :detail eklentisi', ['name' => $name, 'detail' => $operation->detail]) : $name;
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
            return __('Gerekli. Servik tüm sitelerde Nginx kullanır.');
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
     * background. Returns the operation, or the reason it cannot start.
     */
    public function start(string $id, string $action, ?User $user): ComponentOperation|string
    {
        if (! $this->operable($id) || ! in_array($action, ['install', 'update', 'remove'], true)) {
            return __('Bu bileşen buradan yönetilemiyor.');
        }
        // Read first: it also closes operations whose job is gone.
        $statuses = $this->statuses($error);
        if (ComponentOperation::running()->exists() || $this->databaseSetupRunning()) {
            return __('Başka bir kurulum sürüyor. Bitince yeniden deneyin.');
        }
        if (PanelUpdate::running()->exists()) {
            return __('Servik güncelleniyor. Bitince yeniden deneyin.');
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

        $operation = ComponentOperation::create([
            'component' => $id,
            'action' => $action,
            'run' => 'c'.Str::lower(Str::random(23)),
            'user_id' => $user?->id,
        ]);
        RunComponentOperation::dispatch($operation);

        return $operation;
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
     * Roundcube comes with the mail server; SnappyMail is not offered yet.
     *
     * @return array<string, mixed>
     */
    private function webmailRow(string $id, string $name, string $description): array
    {
        $base = ['id' => $id, 'name' => $name, 'description' => $description, 'known' => true, 'version' => null, 'latest' => null,
            'running' => true, 'units' => [], 'operation' => null, 'default' => false, 'settings' => null];
        if ($id === 'snappymail') {
            return $base + ['installed' => false, 'busy' => null, 'usage' => '—', 'refusal' => __('Sonraki sürümde.'), 'later' => true];
        }
        $server = $this->mail->server();
        $installed = $this->mail->isSetUp() && $this->mail->webmailUrl() !== null;
        $mailboxes = $installed ? Mailbox::count() : 0;

        return $base + [
            'installed' => $installed,
            'busy' => ($server['state'] ?? null) === 'installing' ? ($this->mail->webmail() ? 'install' : 'remove') : null,
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
