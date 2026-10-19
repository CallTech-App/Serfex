<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelEnvVersion;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\Redis;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;

/**
 * The application's .env, as a table of variables or as text. Saving
 * keeps the previous file in the history and rebuilds the config cache.
 */
#[Lazy]
class Environment extends Section
{
    protected const ABILITY = 'operate';

    /** Creating a database counts against the owner's package. */
    protected const MANAGE_METHODS = ['createDatabase', 'undoCreateDatabase', 'connectRedis', 'undoConnectRedis'];

    /** How many earlier versions are kept. */
    public const HISTORY = 30;

    /** rows or text */
    public string $mode = 'rows';

    /** @var list<array{key: string, value: string}> */
    public array $rows = [];

    public string $text = '';

    /** The file as loaded, to tell what changed. */
    public ?string $original = null;

    public ?string $example = null;

    public bool $missing = false;

    public bool $comparing = false;

    public bool $showingHistory = false;

    /** Set while another action saves and reports on its own. */
    private bool $quiet = false;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $this->load();
    }

    public function load(): void
    {
        $files = $this->agent('laravel.env.get', $this->app()->agentApp());
        if ($files === null) {
            return;
        }
        $this->missing = $files['env'] === null;
        $this->original = (string) ($files['env'] ?? '');
        $this->example = $files['example'];
        $this->loadContent($this->original);
        $this->resetValidation();
    }

    public function updatedMode(string $mode): void
    {
        // Carry edits over when switching between the two views.
        if ($mode === 'text') {
            $this->text = EnvFile::apply($this->text, $this->values());
        } else {
            $this->rows = $this->rowsOf($this->text);
        }
    }

    public function addRow(string $key = ''): void
    {
        $this->rows[] = ['key' => $key, 'value' => ''];
    }

    public function removeRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    /**
     * Adds the keys .env.example has and .env lacks, empty.
     */
    public function addMissing(): void
    {
        $have = array_column($this->rows, 'key');
        foreach (EnvFile::compare(EnvFile::apply($this->text, $this->values()), $this->example)['missing'] as $key) {
            if (! in_array($key, $have, true)) {
                $this->rows[] = ['key' => $key, 'value' => (string) (EnvFile::values((string) $this->example)[$key] ?? '')];
            }
        }
        $this->comparing = false;
    }

    public function save(AgentClient $agent): void
    {
        $app = $this->app();
        if ($this->mode === 'rows') {
            foreach ($this->rows as $i => $row) {
                $this->rows[$i]['key'] = strtoupper(trim($row['key']));
            }
            $this->validate([
                'rows.*.key' => ['required', 'regex:/^[A-Z_][A-Z0-9_]*$/', 'distinct'],
                'rows.*.value' => ['nullable', 'string', 'max:65535'],
            ], [
                'rows.*.key.required' => 'Anahtar boş olamaz.',
                'rows.*.key.regex' => 'Anahtar büyük harf, rakam ve _ içerebilir.',
                'rows.*.key.distinct' => 'Bu anahtar iki kez var.',
            ]);
            $content = EnvFile::apply($this->text, $this->values());
        } else {
            $this->validate(['text' => ['nullable', 'string', 'max:262144']]);
            $content = str_replace("\r\n", "\n", $this->text);
        }

        $before = $this->original ?? '';
        if ($content === $before) {
            $this->dispatch('toast', message: 'Değişiklik yok.');

            return;
        }
        try {
            $result = $agent->call('laravel.env.set', $app->agentApp() + ['content' => $content], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = '.env kaydedilemedi: '.self::agentMessage($e);

            return;
        }
        $this->agentError = null;
        $version = $app->envVersions()->create([
            'content' => $before,
            'summary' => EnvFile::summary($before, $content),
            'user_name' => auth()->user()?->name,
        ]);
        $app->envVersions()->whereNotIn('id', $app->envVersions()->limit(self::HISTORY)->pluck('id'))->delete();
        $this->original = $content;
        $this->missing = false;
        $this->loadContent($content);
        if ($this->quiet) {
            return;
        }
        $this->dispatch('toast',
            message: ($result['cached'] ?? false) ? '.env kaydedildi, config:cache yenilendi.' : '.env kaydedildi.',
            source: $this->getId(),
            undo: ['method' => 'restore', 'args' => [$version->id]],
        );
    }

    /**
     * Creates a database and a user for the application on the server's
     * database server and writes them into .env (DB_CONNECTION, DB_HOST,
     * DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD).
     */
    public function createDatabase(AgentClient $agent, Databases $databases, DatabaseServers $servers, Quotas $quotas): void
    {
        $app = $this->app();
        $site = $app->site;
        $server = $servers->defaultServer();
        if ($server === null) {
            $this->agentError = 'Bu sunucuda veritabanı sunucusu kurulu değil.';

            return;
        }
        if (($refusal = $quotas->refusal($site->customer(), 'databases')) !== null) {
            $this->agentError = $refusal;

            return;
        }
        $prefix = Databases::prefix($site->systemUser);
        $name = $prefix.'laravel';
        for ($i = 2; Database::withTrashed()->where('server', $server)->where('name', $name)->exists()
            || DatabaseUser::withTrashed()->where('server', $server)->where('name', Str::limit($name, Databases::MAX_USER, ''))->exists(); $i++) {
            $name = $prefix.'laravel'.$i;
        }
        $user = Str::limit($name, Databases::MAX_USER, '');
        $password = Databases::password();
        try {
            $database = $databases->create($site, $server, $name, $user, $password);
        } catch (AgentException $e) {
            $this->agentError = 'Veritabanı oluşturulamadı: '.self::agentMessage($e);

            return;
        }
        $c = $database->connection($user, $password);
        $this->mode = 'text';
        $this->text = EnvFile::apply((string) $this->original, [
            'DB_CONNECTION' => $c['driver'],
            'DB_HOST' => $c['host'],
            'DB_PORT' => $c['port'],
            'DB_DATABASE' => $c['database'],
            'DB_USERNAME' => $c['username'],
            'DB_PASSWORD' => $c['password'],
        ]);
        $this->quiet = true;
        $this->save($agent);
        $this->quiet = false;
        if ($this->agentError !== null) {
            // The application cannot use it; it goes again.
            $this->discard($database, $databases);

            return;
        }
        $app->update(['database_id' => $database->id]);
        $this->mode = 'rows';
        $this->dispatch('toast',
            message: "{$database->name} oluşturuldu ve .env dosyasına yazıldı. Tabloları oluşturmak için Komutlar'dan migrate çalıştırın.",
            source: $this->getId(),
            undo: ['method' => 'undoCreateDatabase', 'args' => [$database->id]],
        );
    }

    /**
     * Gives the application its own Redis instance and moves the cache,
     * sessions and queues there (REDIS_*, CACHE_STORE, SESSION_DRIVER,
     * QUEUE_CONNECTION in .env).
     */
    public function connectRedis(AgentClient $agent, Redis $redis, Components $components): void
    {
        $app = $this->app();
        if ($app->redis_port !== null || $app->isPreview()) {
            return;
        }
        if (! $components->isInstalled('redis')) {
            $this->agentError = 'Bu sunucuda Redis kurulu değil.';

            return;
        }
        try {
            $env = $redis->connect($app);
        } catch (AgentException $e) {
            $this->agentError = 'Redis başlatılamadı: '.self::agentMessage($e);

            return;
        }
        $this->mode = 'text';
        $this->text = EnvFile::apply((string) $this->original, $env);
        $this->quiet = true;
        $this->save($agent);
        $this->quiet = false;
        if ($this->agentError !== null) {
            try {
                $redis->disconnect($app);
            } catch (AgentException) {
            }

            return;
        }
        $this->mode = 'rows';
        $this->dispatch('toast',
            message: "Redis 127.0.0.1:{$app->redis_port} üzerinde başlatıldı; önbellek, oturumlar ve kuyruklar Redis'e alındı. Kuyruk işçileri bir sonraki dağıtımda yeniden başlar.",
            source: $this->getId(),
            undo: ['method' => 'undoConnectRedis', 'args' => []],
        );
    }

    /**
     * Puts the .env back as it was before Redis and deletes the instance.
     */
    public function undoConnectRedis(AgentClient $agent, Redis $redis): void
    {
        $app = $this->app();
        if ($app->redis_port === null) {
            return;
        }
        $version = $app->envVersions()->first();
        if ($version !== null) {
            $this->restore($version->id, $agent);
        }
        try {
            $redis->disconnect($app);
        } catch (AgentException $e) {
            $this->agentError = 'Redis durdurulamadı: '.self::agentMessage($e);
        }
    }

    /**
     * Takes a database created here back: the .env as it was before, the
     * database deleted (no backup, it was empty).
     */
    public function undoCreateDatabase(int $databaseId, AgentClient $agent, Databases $databases): void
    {
        $app = $this->app();
        $database = Database::where('site_id', $app->site_id)->find($databaseId);
        if ($database === null) {
            return;
        }
        $version = $app->envVersions()->first();
        if ($version !== null) {
            $this->restore($version->id, $agent);
        }
        $app->update(['database_id' => null]);
        $this->discard($database, $databases);
    }

    /**
     * Deletes a database made here and the user made with it.
     */
    private function discard(Database $database, Databases $databases): void
    {
        foreach ($database->users()->get() as $user) {
            if ($user->databases()->count() === 1) {
                $databases->deleteUser($user);
            }
        }
        $databases->delete($database, backup: false);
    }

    /**
     * Puts an earlier version of the .env back (it is saved like an edit,
     * so the current file goes into the history).
     */
    public function restore(int $versionId, AgentClient $agent): void
    {
        $version = LaravelEnvVersion::where('laravel_app_id', $this->appId)->find($versionId);
        if ($version === null) {
            return;
        }
        $this->mode = 'text';
        $this->text = $version->content;
        $this->showingHistory = false;
        $this->save($agent);
    }

    public function render(): View
    {
        $app = $this->app();
        $current = $this->original === null ? '' : ($this->mode === 'rows' ? EnvFile::apply($this->text, $this->values()) : $this->text);

        return view('livewire.laravel.sections.environment', [
            'app' => $app,
            'canConnectRedis' => $app->redis_port === null && ! $app->isPreview() && app(Components::class)->isInstalled('redis') && auth()->user()->canOnApp($app, 'manage'),
            'canCreateDatabase' => $app->database_id === null && app(DatabaseServers::class)->installed() !== [] && auth()->user()->canOnApp($app, 'manage') && ! $app->isPreview(),
            'diff' => EnvFile::compare($current, $this->example),
            'history' => $this->showingHistory ? $app->envVersions()->limit(self::HISTORY)->get() : collect(),
        ]);
    }

    private function loadContent(string $content): void
    {
        $this->text = $content;
        $this->rows = $this->rowsOf($content);
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function rowsOf(string $content): array
    {
        $rows = [];
        foreach (EnvFile::values($content) as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $key = strtoupper(trim($row['key']));
            if ($key !== '') {
                $values[$key] = (string) $row['value'];
            }
        }

        return $values;
    }
}
