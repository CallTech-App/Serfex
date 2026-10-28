<?php

namespace App\Services\Laravel;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Jobs\InstallFromTemplate;
use App\Jobs\UpdateFromTemplate;
use App\Models\AppTemplate;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use App\Services\Git\Bitbucket;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitHub;
use App\Services\Git\GitLab;
use App\Services\Git\GitProvider;
use App\Services\Git\GitProviderException;
use App\Services\Git\GitProviders;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Application templates: reading the version new installs get, installing
 * a template on a site (its database, .env, code and one-time setup
 * commands), updating the sites of a Git template, and moving templates
 * between panels as JSON.
 *
 * Values in the .env template and the commands are written as
 * placeholders ({site.url}, {db.password}, {ask.STORE_NAME}…) and filled
 * per install. Commands are split into arguments first and filled
 * argument by argument, so a value never becomes a second argument.
 */
class AppTemplates
{
    /** The placeholders an install fills, for the help texts. */
    public const PLACEHOLDERS = ['{site.domain}', '{site.url}', '{site.path}', '{customer.name}', '{customer.email}',
        '{db.connection}', '{db.host}', '{db.port}', '{db.name}', '{db.user}', '{db.password}',
        '{admin.name}', '{admin.email}', '{admin.password}', '{ask.ANAHTAR}', '{file.ANAHTAR}', '{random:32}'];

    /** Minutes the version read from the Git host is kept. */
    public const CHECK_MINUTES = 10;

    /** What a command line may start with, and the tool it runs. */
    private const COMMAND_PREFIXES = ['php artisan' => 'artisan', 'composer' => 'composer', 'npm' => 'npm', 'pnpm' => 'pnpm', 'yarn' => 'yarn', 'bun' => 'bun'];

    /** Where files asked at install are written, inside the application. */
    private const FILE_DIR = 'storage/app/serfex';

    /** Version of the export format. */
    public const EXPORT_VERSION = 1;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly GitAccounts $accounts,
        private readonly GitProviders $providers,
        private readonly DatabaseServers $servers,
    ) {}

    /**
     * Splits the command lines of a template ("php artisan …", "composer
     * …"), one command per line; blank lines and # comments are skipped.
     *
     * @return list<array{tool: string, args: list<string>}>
     *
     * @throws InvalidArgumentException naming the line that is not valid
     */
    public static function parseCommands(?string $text): array
    {
        $commands = [];
        foreach (preg_split('/\r?\n/', (string) $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            try {
                $words = CommandLine::split($line);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException(__(':line. satır: :reason', ['line' => $i + 1, 'reason' => $e->getMessage()]));
            }
            $tool = null;
            if (($words[0] ?? '') === 'php' && ($words[1] ?? '') === 'artisan') {
                $tool = 'artisan';
                $args = array_slice($words, 2);
            } elseif (in_array($words[0] ?? '', CommandLine::TOOLS, true) && $words[0] !== 'artisan') {
                $tool = $words[0];
                $args = array_slice($words, 1);
            }
            if ($tool === null || $args === []) {
                throw new InvalidArgumentException(__(':line. satır "php artisan", "composer", "npm", "pnpm", "yarn" veya "bun" ile başlamalı.', ['line' => $i + 1]));
            }
            $commands[] = ['tool' => $tool, 'args' => array_values($args)];
        }
        if (count($commands) > 20) {
            throw new InvalidArgumentException(__('En fazla 20 komut yazılabilir.'));
        }

        return $commands;
    }

    /**
     * The KEY=value lines of a .env template.
     *
     * @return array<string, string>
     *
     * @throws InvalidArgumentException naming the key that is not valid
     */
    public static function parseEnv(?string $text): array
    {
        foreach (preg_split('/\r?\n/', (string) $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $key = trim(Str::before(preg_replace('/^export\s+/', '', $line), '='));
            if (! str_contains($line, '=') || ! EnvFile::isValidKey($key)) {
                throw new InvalidArgumentException(__(':line. satır ANAHTAR=değer biçiminde olmalı.', ['line' => $i + 1]));
            }
        }

        return EnvFile::values((string) $text);
    }

    /**
     * Replaces the placeholders of $text; {random:N} becomes N random
     * letters and digits (8 to 64). Unknown placeholders stay as written.
     *
     * @param  array<string, string>  $values  "site.domain" => value
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z]+(?:\.[A-Za-z0-9_]+)?|random:(\d{1,2}))\}/', function (array $m) use ($values) {
            if (isset($m[2]) && $m[2] !== '') {
                return Str::random(max(8, min(64, (int) $m[2])));
            }

            return array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0];
        }, $text);
    }

    /**
     * Reads the version new installs get from the Git host: the newest
     * commit of the branch, or the newest release and its package. Kept
     * for a few minutes unless $force.
     */
    public function check(AppTemplate $template, bool $force = false): void
    {
        if (! $force && $template->checked_at !== null && $template->checked_at->gt(now()->subMinutes(self::CHECK_MINUTES))) {
            return;
        }
        if ($template->isPackage() && $template->package_url && ! $template->remote_repository) {
            // A typed address: the version is known once it is unpacked.
            $template->update(['checked_at' => now(), 'check_error' => null, 'latest_package' => ['name' => basename((string) parse_url($template->package_url, PHP_URL_PATH)), 'url' => $template->package_url, 'size' => 0, 'sha256' => null]]);

            return;
        }
        try {
            [$provider, $token, $repository] = $this->api($template);
            if ($template->isPackage()) {
                $release = $provider->latestRelease($token, $repository);
                $asset = $this->pickAsset($template, $release['assets']);
                if ($asset === null) {
                    throw new GitProviderException(__(':tag sürümünde ":pattern" adına uyan bir dosya yok.', ['tag' => $release['tag'], 'pattern' => $template->package_asset ?: '*.zip']));
                }
                $template->update([
                    'latest_version' => ltrim($release['tag'], 'vV') !== '' ? $release['tag'] : null,
                    'latest_package' => $asset,
                    'checked_at' => now(),
                    'check_error' => null,
                ]);
            } else {
                $commit = $provider->commit($token, $repository, (string) $template->ref);
                $template->update([
                    'latest_commit' => $commit['sha'] ?: null,
                    'latest_version' => $template->ref_type === 'tag' ? $template->ref : ($commit['sha'] ? substr($commit['sha'], 0, 7) : null),
                    'checked_at' => now(),
                    'check_error' => null,
                ]);
            }
        } catch (GitProviderException $e) {
            $template->update(['checked_at' => now(), 'check_error' => Str::limit($e->getMessage(), 480)]);
        }
    }

    /**
     * Branches or tags of the template's repository, for its version field.
     *
     * @return list<string>
     *
     * @throws GitProviderException
     */
    public function refs(AppTemplate $template, string $type): array
    {
        [$provider, $token, $repository] = $this->api($template);
        if ($type === 'tag') {
            return $provider->tags($token, $repository);
        }

        return $template->gitAccount
            ? $this->accounts->branches($template->gitAccount, $repository)
            : $provider->branches($token, $repository);
    }

    /**
     * The host's API for the template, with the account's token (or none,
     * for a public repository), and the repository's full name.
     *
     * @return array{0: GitProvider, 1: ?string, 2: string}
     *
     * @throws GitProviderException
     */
    private function api(AppTemplate $template): array
    {
        $account = $template->gitAccount;
        $repository = $template->remote_repository ?: $this->fullName($template);
        if ($repository === null) {
            throw new GitProviderException(__('Sürümü okumak için deponun bir Git hesabıyla seçilmesi ya da GitHub, GitLab veya Bitbucket adresi olması gerekir.'));
        }
        if ($account !== null) {
            $provider = $this->accounts->provider($account) ?? throw new GitProviderException(__(':provider bağlantısı bu sunucuda kapatılmış.', ['provider' => GitProviders::LABELS[$account->provider] ?? $account->provider]));

            return [$provider, $this->accounts->token($account), $repository];
        }
        // A public repository is read without signing in.
        $provider = $this->providers->get($template->provider) ?? match ($template->provider) {
            'gitlab' => new GitLab(['client_id' => '', 'client_secret' => '', 'url' => 'https://gitlab.com']),
            'bitbucket' => new Bitbucket(['client_id' => '', 'client_secret' => '']),
            default => new GitHub(['client_id' => '', 'client_secret' => '']),
        };

        return [$provider, null, $repository];
    }

    /**
     * "owner/name" of a typed repository address on a known host.
     */
    private function fullName(AppTemplate $template): ?string
    {
        $repo = (string) $template->repository;
        $hosts = ['github' => 'github.com', 'gitlab' => 'gitlab.com', 'bitbucket' => 'bitbucket.org'];
        $host = $hosts[$template->provider] ?? null;
        if ($host === null) {
            return null;
        }
        if (preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) {
            return preg_replace('~\.git$~', '', $repo);
        }
        if (preg_match('~^(?:https://|git@)'.preg_quote($host, '~').'[/:](.+?)(?:\.git)?/?$~', $repo, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * The release file matching the template's name pattern ("shop-*.zip");
     * without one, the only .zip.
     *
     * @param  list<array{name: string, url: string, size: int, sha256: ?string}>  $assets
     * @return array{name: string, url: string, size: int, sha256: ?string}|null
     */
    private function pickAsset(AppTemplate $template, array $assets): ?array
    {
        $pattern = $template->package_asset ?: '*.zip';
        $matching = array_values(array_filter($assets, fn (array $a) => fnmatch($pattern, $a['name'])));

        return count($matching) >= 1 && ($template->package_asset || count($matching) === 1) ? $matching[0] : null;
    }

    /**
     * The address Git clones the template's repository from: over SSH
     * with each site's deploy key when it is private.
     */
    public static function cloneUrl(AppTemplate $template): string
    {
        $repo = (string) $template->repository;
        $host = ['github' => 'github.com', 'gitlab' => 'gitlab.com', 'bitbucket' => 'bitbucket.org'][$template->provider] ?? 'github.com';
        if (preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) {
            $path = preg_replace('~\.git$~', '', $repo).'.git';

            return $template->private ? "git@{$host}:{$path}" : "https://{$host}/{$path}";
        }
        if ($template->private && preg_match('~^https://([A-Za-z0-9.-]+)/(.+)$~', $repo, $m)) {
            return "git@{$m[1]}:".preg_replace('~\.git$~', '', $m[2]).'.git';
        }

        return $repo;
    }

    /**
     * Starts installing a template on a site. The application appears at
     * once with its install queued; the work waits until the site is
     * ready (a new site is created and gets its certificate first).
     *
     * @param  array<string, string>  $answers  question key => answer
     * @param  array<string, array{name: string, content: string}>  $files  question key => uploaded file
     * @param  array{name: string, email: string, password: string}|null  $admin
     */
    public function install(AppTemplate $template, Site $site, array $answers, array $files, ?array $admin, ?User $user = null): LaravelApp
    {
        return DB::transaction(function () use ($template, $site, $answers, $files, $admin, $user) {
            if ($template->php_version && $template->php_version !== $site->php_version) {
                // Applied when the application goes live.
                $site->update(['php_version' => $template->php_version]);
            }
            $package = $template->isPackage();
            $app = LaravelApp::create([
                'site_id' => $site->id,
                'path' => $template->appPath(),
                'status' => LaravelAppStatus::Installing,
                'source' => $package ? 'package' : 'git',
                'repository' => $package ? null : self::cloneUrl($template),
                'branch' => $package ? null : $template->ref,
                'frontend' => ! $package && $template->frontend,
                'package_manager' => $package ? null : $template->package_manager,
                'migrate' => ! $package && $template->migrate,
                'scheduler' => $template->scheduler,
                'hooks' => $package ? [] : $template->hookScripts(),
                'health_path' => $template->health_path ?: '/up',
                'auto_rollback' => $template->update_rollback,
                'backup_before_deploy' => $template->update_backup,
                'app_template_id' => $template->id,
                'template_setup' => [
                    'answers' => $answers,
                    'files' => array_map(fn (array $f) => ['name' => $f['name'], 'content' => base64_encode($f['content'])], $files),
                    'admin' => $template->create_admin ? $admin : null,
                ],
            ]);
            if ($template->worker) {
                $app->workers()->create(['name' => 'default']);
            }
            $deployment = LaravelDeployment::create([
                'laravel_app_id' => $app->id,
                'number' => 1,
                'kind' => 'install',
                'status' => DeploymentStatus::Queued,
                'ref' => $package ? null : $template->ref,
                'message' => $package ? __('Şablon :name', ['name' => $template->name]) : null,
                'triggered_by' => $user?->name,
                'run' => 'install-'.Str::lower(Str::random(12)),
                'migrate' => $app->migrate,
                'frontend' => $app->frontend,
            ]);
            InstallFromTemplate::dispatch($deployment)->afterCommit();

            return $app;
        });
    }

    /**
     * The install's database: created on the template's server under the
     * account's prefix ("kahve_magaza", "kahve_magaza_2" when taken), once.
     * Returns its connection, or null for SQLite or no database.
     *
     * @return array{driver: string, host: string, port: string, database: string, username: string, password: string}|null
     *
     * @throws AgentException
     */
    public function database(LaravelApp $app, AppTemplate $template): ?array
    {
        $setup = $app->template_setup ?? [];
        if (isset($setup['db'])) {
            return $setup['db'];
        }
        if (! in_array($template->database, [DatabaseServers::MYSQL, DatabaseServers::POSTGRESQL], true)) {
            return null;
        }
        if (! $this->servers->isInstalled($template->database)) {
            throw new AgentException(__(':server bu sunucuda kurulu değil; Yazılım Yönetimi\'nden kurun ya da şablonun veritabanını değiştirin.', ['server' => $template->database === DatabaseServers::POSTGRESQL ? 'PostgreSQL' : 'MySQL']));
        }
        $site = $app->site;
        $base = Databases::prefix($site->systemUser).$template->databaseSuffix();
        $name = $base;
        for ($n = 2; $this->nameTaken($template->database, $name); $n++) {
            $name = Str::limit($base, Databases::MAX_USER - 3, '').'_'.$n;
        }
        $user = Str::limit($name, Databases::MAX_USER, '');
        $password = Databases::password();
        $database = app(Databases::class)->create($site, $template->database, $name, $user, $password);
        $connection = $database->connection($user, $password);
        $app->update(['database_id' => $database->id, 'template_setup' => $setup + ['db' => $connection]]);

        return $connection;
    }

    private function nameTaken(string $server, string $name): bool
    {
        return Database::withTrashed()->where('server', $server)->where('name', $name)->exists()
            || DatabaseUser::withTrashed()->where('server', $server)->where('name', Str::limit($name, Databases::MAX_USER, ''))->exists();
    }

    /**
     * The values placeholders stand for in this install.
     *
     * @param  array<string, string>|null  $db
     * @return array<string, string>
     */
    public function values(LaravelApp $app, ?array $db): array
    {
        $site = $app->site;
        $setup = $app->template_setup ?? [];
        $customer = $site->customer();
        $values = [
            'site.domain' => $site->domain,
            'site.url' => ($site->hasHttps() ? 'https://' : 'http://').$site->primaryHost(),
            'site.path' => $app->directory(),
            'customer.name' => $customer?->displayName() ?? $site->domain,
            'customer.email' => (string) ($customer?->email ?? ''),
            'db.connection' => $db['driver'] ?? ($app->template?->database === 'sqlite' ? 'sqlite' : ''),
            'db.host' => $db['host'] ?? '',
            'db.port' => $db['port'] ?? '',
            'db.name' => $db['database'] ?? ($app->template?->database === 'sqlite' ? $this->sqlitePath($app) : ''),
            'db.user' => $db['username'] ?? '',
            'db.password' => $db['password'] ?? '',
            'admin.name' => (string) ($setup['admin']['name'] ?? ''),
            'admin.email' => (string) ($setup['admin']['email'] ?? ''),
            'admin.password' => (string) ($setup['admin']['password'] ?? ''),
        ];
        foreach ($setup['answers'] ?? [] as $key => $answer) {
            $values['ask.'.$key] = (string) $answer;
        }
        foreach ($setup['files'] ?? [] as $key => $file) {
            $values['ask.'.$key] = $values['file.'.$key] = $app->directory().'/'.$this->filePath($key, $file['name']);
        }

        return $values;
    }

    /**
     * Where an uploaded file goes inside the application.
     */
    private function filePath(string $key, string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return self::FILE_DIR.'/'.strtolower($key).(preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.'.$ext : '');
    }

    private function sqlitePath(LaravelApp $app): string
    {
        return $app->isPackage()
            ? $app->directory().'/database/database.sqlite'
            : dirname($app->directory()).'/shared/database/database.sqlite';
    }

    /**
     * The first .env of the install: what Serfex writes for every Laravel
     * application (APP_URL, the database…), then the template's lines.
     *
     * @param  array<string, string>|null  $db
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    public function env(LaravelApp $app, AppTemplate $template, ?array $db, array $values): array
    {
        $env = app(LaravelApps::class)->installEnv($app->site, [
            'database' => $db !== null ? 'existing' : ($template->database === 'sqlite' ? 'sqlite' : 'none'),
            'db' => $db,
        ]);
        $env['APP_NAME'] = $values['customer.name'];
        if ($template->database === 'sqlite') {
            $env['DB_DATABASE'] = $this->sqlitePath($app);
        }
        if ($app->isPackage()) {
            $env['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
        }
        foreach (self::parseEnv($template->env_template) as $key => $value) {
            $env[$key] = self::fill($value, $values);
        }

        return $env;
    }

    /**
     * The template's setup commands and its first administrator's,
     * filled for this install.
     *
     * @param  array<string, string>  $values
     * @return list<array{tool: string, args: list<string>}>
     */
    public function commands(AppTemplate $template, array $values, bool $withAdmin): array
    {
        $commands = self::parseCommands($template->setup_commands);
        if ($withAdmin && $template->create_admin && filled($template->admin_command)) {
            $commands = [...$commands, ...self::parseCommands($template->admin_command)];
        }

        return array_map(fn (array $c) => [
            'tool' => $c['tool'],
            'args' => array_map(fn (string $a) => self::fill($a, $values), $c['args']),
        ], $commands);
    }

    /**
     * The values the run log hides: passwords and secret answers.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function secrets(AppTemplate $template, array $values): array
    {
        $secrets = [$values['db.password'] ?? '', $values['admin.password'] ?? ''];
        foreach ($template->questions() as $q) {
            if ($q['secret']) {
                $secrets[] = $values['ask.'.$q['key']] ?? '';
            }
        }

        return array_values(array_filter($secrets, fn (string $s) => strlen($s) >= 4));
    }

    /**
     * Runs the one-time setup commands into the install's run log.
     * Returns why they stopped, or null. The answers and the first
     * administrator's password are forgotten afterwards either way.
     */
    public function runSetup(LaravelApp $app, string $run): ?string
    {
        $template = $app->template;
        if ($template === null || $app->template_setup === null) {
            return null;
        }
        $setup = $app->template_setup;
        $values = $this->values($app, $setup['db'] ?? null);
        try {
            $commands = $this->commands($template, $values, $setup['admin'] !== null);
        } catch (InvalidArgumentException $e) {
            $app->update(['template_setup' => null]);

            return __('Kurulum adımları okunamadı: :reason', ['reason' => $e->getMessage()]);
        }
        $problem = null;
        if ($commands !== []) {
            try {
                $result = $this->agent->call('laravel.setup', $app->agentApp() + [
                    'run' => $run,
                    'commands' => $commands,
                    'redact' => $this->secrets($template, $values),
                ], config('serfex.agent.deploy_timeout'));
                if (($result['failed'] ?? -1) >= 0) {
                    $failed = $commands[$result['failed']];
                    $problem = __('Kurulum adımı başarısız: :command', ['command' => CommandLine::prefix($failed['tool']).' '.($failed['args'][0] ?? '')]);
                }
            } catch (AgentException $e) {
                $problem = __('Kurulum adımları çalıştırılamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]);
            }
        }
        $app->update(['template_setup' => null]);

        return $problem;
    }

    /**
     * The download of the template's newest release package: its address,
     * the headers the agent sends and its SHA-256 when the host gave one.
     *
     * @return array{url: string, headers: array<string, string>, sha256: string}
     *
     * @throws GitProviderException
     */
    public function packageDownload(AppTemplate $template): array
    {
        $this->check($template, force: true);
        if ($template->check_error !== null) {
            throw new GitProviderException($template->check_error);
        }
        $package = $template->latest_package ?? [];
        if (! is_string($package['url'] ?? null) || $package['url'] === '') {
            throw new GitProviderException(__('Şablonun sürüm paketi bulunamadı.'));
        }
        $headers = [];
        if (str_starts_with($package['url'], 'https://api.github.com/')) {
            $headers['Accept'] = 'application/octet-stream';
            if ($template->gitAccount !== null) {
                $headers['Authorization'] = 'Bearer '.$this->accounts->token($template->gitAccount);
            }
        }

        return ['url' => $package['url'], 'headers' => $headers, 'sha256' => (string) ($package['sha256'] ?? '')];
    }

    /**
     * The files asked at install, as the agent writes them.
     *
     * @return list<array{path: string, content: string}>
     */
    public function files(LaravelApp $app): array
    {
        $out = [];
        foreach (($app->template_setup ?? [])['files'] ?? [] as $key => $file) {
            // base64 in JSON, as Go's []byte expects.
            $out[] = ['path' => $this->filePath((string) $key, (string) $file['name']), 'content' => (string) $file['content']];
        }

        return $out;
    }

    /**
     * Updates one application of a Git template to the template's version.
     */
    public function update(LaravelApp $app, ?User $user = null): void
    {
        UpdateFromTemplate::dispatch($app, $user?->name);
    }

    /**
     * Updates the template's applications that are behind, one after the
     * other; with $stopOnFailure the first that fails stops the rest.
     * Returns how many were queued.
     */
    public function updateAll(AppTemplate $template, bool $stopOnFailure, ?User $user = null): int
    {
        $apps = $template->apps()->with('site')->where('status', LaravelAppStatus::Active)->get()
            ->filter(fn (LaravelApp $a) => $template->isBehind($a) && ! $a->hasRunningDeployment())
            ->sortBy(fn (LaravelApp $a) => $a->site->domain)->values();
        if ($apps->isEmpty()) {
            return 0;
        }
        Bus::chain($apps->map(fn (LaravelApp $a) => new UpdateFromTemplate($a, $user?->name, $stopOnFailure))->all())->dispatch();

        return $apps->count();
    }

    /**
     * Reads the version each application of a package template runs
     * now (they update themselves).
     */
    public function readVersions(AppTemplate $template): void
    {
        foreach ($template->apps()->with('site.systemUser')->where('status', LaravelAppStatus::Active)->get() as $app) {
            try {
                $version = (string) ($this->agent->call('laravel.version', $app->agentApp() + ['file' => $template->version_file ?: 'VERSION'])['version'] ?? '');
            } catch (AgentException) {
                continue;
            }
            if ($version !== '' && $version !== $app->template_version) {
                $app->update(['template_version' => $version]);
            }
        }
    }

    /**
     * The template as JSON for another panel: without the Git account,
     * versions read and installed sites.
     *
     * @return array<string, mixed>
     */
    public function export(AppTemplate $template): array
    {
        return [
            'serfex_app_template' => self::EXPORT_VERSION,
            ...$template->only(['name', 'description', 'source', 'provider', 'repository', 'remote_repository', 'private', 'ref_type', 'ref',
                'package_asset', 'package_url', 'php_version', 'database', 'frontend', 'package_manager', 'worker', 'scheduler',
                'env_template', 'fields', 'setup_commands', 'create_admin', 'admin_command', 'migrate', 'hooks',
                'update_maintenance', 'update_backup', 'update_rollback', 'customers_can_install', 'health_path', 'version_file']),
        ];
    }

    /**
     * Creates a template from an export. The Git account is chosen again
     * on the template's page.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException when it is not a Serfex template
     */
    public function import(array $data): AppTemplate
    {
        if (($data['serfex_app_template'] ?? null) !== self::EXPORT_VERSION || ! is_string($data['name'] ?? null)) {
            throw new InvalidArgumentException(__('Bu dosya bir Serfex uygulama şablonu değil.'));
        }
        $string = fn (string $key, int $max, ?string $default = null) => is_string($data[$key] ?? null) ? Str::limit($data[$key], $max, '') : $default;
        $bool = fn (string $key, bool $default) => is_bool($data[$key] ?? null) ? $data[$key] : $default;
        $in = fn (string $key, array $allowed, string $default) => in_array($data[$key] ?? null, $allowed, true) ? $data[$key] : $default;
        $fields = [];
        foreach (array_slice(is_array($data['fields'] ?? null) ? $data['fields'] : [], 0, AppTemplate::MAX_FIELDS) as $f) {
            if (is_array($f) && is_string($f['key'] ?? null) && preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $f['key'])) {
                $fields[] = [
                    'key' => $f['key'],
                    'label' => Str::limit((string) ($f['label'] ?? $f['key']), 80, ''),
                    'default' => Str::limit((string) ($f['default'] ?? ''), 200, ''),
                    'required' => (bool) ($f['required'] ?? false),
                    'secret' => (bool) ($f['secret'] ?? false),
                    'type' => ($f['type'] ?? 'text') === 'file' ? 'file' : 'text',
                ];
            }
        }
        $hooks = is_array($data['hooks'] ?? null) ? $data['hooks'] : [];
        $commands = $string('setup_commands', 4000);
        $admin = $string('admin_command', 1000);
        self::parseCommands($commands);
        self::parseCommands($admin);
        self::parseEnv($string('env_template', 8000));

        return AppTemplate::create([
            'name' => $this->uniqueName($string('name', 60)),
            'description' => $string('description', 300),
            'source' => $in('source', AppTemplate::SOURCES, 'git'),
            'provider' => $in('provider', array_keys(AppTemplate::PROVIDERS), 'github'),
            'repository' => $string('repository', 300),
            'remote_repository' => $string('remote_repository', 300),
            'private' => $bool('private', false),
            'ref_type' => $in('ref_type', ['branch', 'tag'], 'branch'),
            'ref' => $string('ref', 200),
            'package_asset' => $string('package_asset', 200),
            'package_url' => is_string($data['package_url'] ?? null) && str_starts_with($data['package_url'], 'https://') ? Str::limit($data['package_url'], 2048, '') : null,
            'php_version' => is_string($data['php_version'] ?? null) && preg_match('/^\d\.\d{1,2}$/', $data['php_version']) ? $data['php_version'] : null,
            'database' => $in('database', array_keys(AppTemplate::DATABASES), 'mysql'),
            'frontend' => $bool('frontend', true),
            'package_manager' => $in('package_manager', LaravelApps::PACKAGE_MANAGERS, 'npm'),
            'worker' => $bool('worker', true),
            'scheduler' => $bool('scheduler', true),
            'env_template' => $string('env_template', 8000),
            'fields' => $fields,
            'setup_commands' => $commands,
            'create_admin' => $bool('create_admin', false),
            'admin_command' => $admin,
            'migrate' => $bool('migrate', true),
            'hooks' => [
                'before_build' => Str::limit((string) ($hooks['before_build'] ?? ''), 8000, ''),
                'before_activate' => Str::limit((string) ($hooks['before_activate'] ?? ''), 8000, ''),
                'after_activate' => Str::limit((string) ($hooks['after_activate'] ?? ''), 8000, ''),
            ],
            'update_maintenance' => $bool('update_maintenance', true),
            'update_backup' => $bool('update_backup', true),
            'update_rollback' => $bool('update_rollback', true),
            // Opening a template to customers is a decision made here.
            'customers_can_install' => false,
            'health_path' => is_string($data['health_path'] ?? null) && str_starts_with($data['health_path'], '/') ? Str::limit($data['health_path'], 200, '') : '/up',
            'version_file' => $string('version_file', 200, 'VERSION'),
        ]);
    }

    /**
     * "Mağaza", or "Mağaza (2)" when the name is taken.
     */
    public function uniqueName(string $name): string
    {
        $name = trim($name) !== '' ? trim($name) : 'Şablon';
        $candidate = $name;
        for ($n = 2; AppTemplate::where('name', $candidate)->exists(); $n++) {
            $candidate = Str::limit($name, 54, '')." ({$n})";
        }

        return $candidate;
    }
}
