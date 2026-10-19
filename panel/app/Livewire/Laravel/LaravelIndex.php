<?php

namespace App\Livewire\Laravel;

use App\Enums\SiteStatus;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Databases\DatabaseServers;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\PhpConstraint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every Laravel application on this server, the install wizard and the
 * search for applications already on sites.
 */
#[Layout('components.layouts.app')]
#[Title('Laravel · Servik')]
class LaravelIndex extends Component
{
    use WithPagination;

    /** Git hosts offered in the wizard: label and host name. */
    public const PROVIDERS = ['github' => ['GitHub', 'github.com'], 'gitlab' => ['GitLab', 'gitlab.com'], 'bitbucket' => ['Bitbucket', 'bitbucket.org'], 'other' => ['Başka adres', null]];

    /** Only administrators and customers (on their own sites) install and adopt applications. */
    public const ADMIN_METHODS = ['openInstall', 'install', 'backToForm', 'scan', 'adopt', 'undoAdopt'];

    /** The PHP a new project needs (Laravel 13). */
    public const NEW_PROJECT_PHP = '^8.3';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $installing = false;

    /** form, or key while showing the deploy key of a private repository. */
    public string $step = 'form';

    public ?int $siteId = null;

    public string $source = 'create';

    public string $kit = '';

    public string $provider = 'github';

    public string $repository = '';

    public bool $private = false;

    public string $branch = 'main';

    public string $phpVersion = '';

    public string $database = 'sqlite';

    public string $dbDriver = 'mysql';

    public string $dbHost = '127.0.0.1';

    public string $dbPort = '3306';

    public string $dbName = '';

    public string $dbUser = '';

    public string $dbPassword = '';

    /** "Yeni veritabanı": the server and the name after the account prefix. */
    public string $newDbServer = '';

    public string $newDbSuffix = 'laravel';

    public bool $frontend = true;

    public string $packageManager = 'npm';

    public bool $worker = true;

    public bool $scheduler = true;

    public ?string $deployKey = null;

    /** @var list<string> */
    public array $phpVersions = [];

    public ?string $agentError = null;

    public bool $scanning = false;

    /** @var list<array<string, mixed>>|null */
    public ?array $found = null;

    public int $scannedSites = 0;

    public function mount(): void
    {
        // "Laravel kur" from a site's page.
        $site = Site::visibleTo(auth()->user())->find((int) request()->query('site'));
        if ($site !== null && $site->laravelApp()->doesntExist()) {
            $this->openInstall(app(AgentClient::class), app(Quotas::class), $site->id);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openInstall(AgentClient $agent, Quotas $quotas, ?int $siteId = null): void
    {
        if (($refusal = $quotas->refusal($this->customer(), 'laravel_apps')) !== null) {
            $this->dispatch('toast', message: $refusal, source: $this->getId());

            return;
        }
        $this->reset('step', 'siteId', 'source', 'kit', 'provider', 'repository', 'private', 'branch', 'phpVersion', 'database', 'dbDriver', 'dbHost',
            'dbPort', 'dbName', 'dbUser', 'dbPassword', 'newDbSuffix', 'frontend', 'packageManager', 'worker', 'scheduler', 'deployKey', 'agentError');
        $this->resetValidation();
        $this->installing = true;
        // A database of its own when the server has a database server.
        $servers = app(DatabaseServers::class);
        $this->newDbServer = (string) $servers->defaultServer();
        if ($this->newDbServer !== '') {
            $this->database = 'new';
        }
        try {
            $this->phpVersions = array_values($agent->call('php.versions') ?? []);
            if (($package = $this->customer()?->package) !== null) {
                $this->phpVersions = $package->phpVersionsFrom($this->phpVersions);
            }
        } catch (AgentException $e) {
            $this->phpVersions = [];
            $this->agentError = 'Sunucu ajanına ulaşılamıyor: '.($e->reason ?: $e->getMessage());
        }
        $this->siteId = $siteId ?? $this->freeSites()->first()?->id;
        $this->updatedSiteId();
    }

    public function updatedSiteId(): void
    {
        $site = $this->siteId ? Site::visibleTo(auth()->user())->find($this->siteId) : null;
        if ($site !== null) {
            $this->phpVersion = $site->php_version;
        }
    }

    public function updatedDbDriver(): void
    {
        $this->dbPort = $this->dbDriver === 'pgsql' ? '5432' : '3306';
    }

    /**
     * Checks the form; for a private repository shows the deploy key first.
     */
    public function install(AgentClient $agent, LaravelApps $apps, Quotas $quotas): void
    {
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->validate($this->rules(), [
            'siteId.required' => 'Bir site seçin.',
            'repository.required_if' => 'Depo adresini girin.',
            'repository.regex' => 'Depo adresi "kullanıcı/depo", https://… veya git@… biçiminde olmalı.',
            'branch.regex' => 'Dal veya etiket adı geçersiz.',
        ], [
            'phpVersion' => 'PHP sürümü',
            'dbName' => 'veritabanı adı',
            'dbUser' => 'kullanıcı adı',
            'dbHost' => 'sunucu',
            'dbPort' => 'port',
            'newDbSuffix' => 'veritabanı adı',
        ]);
        $site = Site::visibleTo(auth()->user())->with('systemUser')->findOrFail($this->siteId);
        if (($refusal = $quotas->refusal($site->customer(), 'laravel_apps')) !== null) {
            $this->agentError = $refusal;

            return;
        }
        $newDb = $this->database === 'new' ? Databases::prefix($site->systemUser).$this->newDbSuffix : null;
        if ($newDb !== null) {
            if (($refusal = $quotas->refusal($site->customer(), 'databases')) !== null) {
                $this->agentError = $refusal;

                return;
            }
            if (Database::withTrashed()->where('server', $this->newDbServer)->where('name', $newDb)->exists()
                || DatabaseUser::withTrashed()->where('server', $this->newDbServer)->where('name', Str::limit($newDb, Databases::MAX_USER, ''))->exists()) {
                $this->addError('newDbSuffix', "{$newDb} adında bir veritabanı ya da kullanıcı zaten var; başka bir ad girin.");

                return;
            }
        }

        if ($this->source === 'git' && $this->private && $this->step === 'form') {
            try {
                $this->deployKey = $agent->call('laravel.key', [
                    'domain' => $site->domain, 'user' => $site->systemUser->name, 'php_version' => $site->php_version,
                ], config('servik.agent.job_timeout'))['public_key'] ?? null;
            } catch (AgentException $e) {
                $this->agentError = 'Dağıtım anahtarı oluşturulamadı: '.($e->reason ?: $e->getMessage());

                return;
            }
            $this->step = 'key';

            return;
        }

        $database = null;
        $db = ['driver' => $this->dbDriver, 'host' => $this->dbHost, 'port' => $this->dbPort, 'database' => $this->dbName, 'username' => $this->dbUser, 'password' => $this->dbPassword];
        if ($newDb !== null) {
            // The database and its user, created now so the first .env can
            // carry them; the password goes only there.
            $password = Databases::password();
            $user = Str::limit($newDb, Databases::MAX_USER, '');
            try {
                $database = app(Databases::class)->create($site, $this->newDbServer, $newDb, $user, $password);
            } catch (AgentException $e) {
                $this->agentError = 'Veritabanı oluşturulamadı: '.$e->reason;

                return;
            }
            $db = $database->connection($user, $password);
        }

        $app = $apps->install($site, [
            'source' => $this->source,
            'kit' => $this->kit,
            'repository' => $this->repositoryUrl(),
            'branch' => $this->branch,
            'php_version' => $this->phpVersion,
            'database' => $newDb !== null ? 'existing' : $this->database,
            'db' => $db,
            'frontend' => $this->frontend,
            'package_manager' => $this->packageManager,
            'worker' => $this->worker,
            'scheduler' => $this->scheduler,
        ], auth()->user());
        if ($this->deployKey !== null) {
            $app->update(['deploy_key' => $this->deployKey]);
        }
        if ($database !== null) {
            $app->update(['database_id' => $database->id]);
        }

        session()->flash('toast', 'Kurulum başladı. Adımları bu sayfada canlı izleyebilirsiniz.');
        $this->redirectRoute('laravel.show', $app, navigate: true);
    }

    public function backToForm(): void
    {
        $this->step = 'form';
    }

    /**
     * Looks for Laravel applications on sites that have none in Servik yet.
     */
    public function scan(AgentClient $agent): void
    {
        $this->scanning = true;
        $this->found = null;
        $this->agentError = null;
        $sites = $this->freeSites();
        $this->scannedSites = $sites->count();
        if ($sites->isEmpty()) {
            $this->found = [];

            return;
        }
        try {
            $found = $agent->call('laravel.scan', [
                'sites' => $sites->map(fn (Site $s) => ['domain' => $s->domain, 'user' => $s->systemUser->name])->values()->all(),
            ], config('servik.agent.job_timeout')) ?? [];
        } catch (AgentException $e) {
            $this->agentError = 'Tarama yapılamadı: '.($e->reason ?: $e->getMessage());

            return;
        }
        $byDomain = $sites->keyBy('domain');
        $this->found = collect($found)->map(function (array $f) use ($byDomain) {
            $site = $byDomain[$f['domain']] ?? null;

            return $site === null ? null : [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'path' => $f['path'],
                'laravel' => $f['laravel'] ?? '',
                'php_requirement' => $f['php_requirement'] ?? '',
                'package_manager' => $f['package_manager'] ?? '',
                'php' => $site->php_version,
                'compatible' => ($f['php_requirement'] ?? '') === '' ? null : PhpConstraint::allows($f['php_requirement'], $site->php_version),
                'served' => ($site->web_root ?? 'public_html') === $f['path'].'/public',
            ];
        })->filter()->values()->all();
    }

    /**
     * Manage a found application where it is.
     */
    public function adopt(int $index, LaravelApps $apps, Quotas $quotas): void
    {
        $f = $this->found[$index] ?? null;
        $site = $f ? Site::visibleTo(auth()->user())->find($f['site_id']) : null;
        if ($site === null || $site->laravelApp()->exists()) {
            return;
        }
        if (($refusal = $quotas->refusal($site->customer(), 'laravel_apps')) !== null) {
            $this->dispatch('toast', message: $refusal, source: $this->getId());

            return;
        }
        $before = $site->web_root;
        $app = $apps->adopt($site, $f);
        unset($this->found[$index]);
        $this->found = array_values($this->found);
        $this->dispatch('toast',
            message: "{$site->domain} Servik'e bağlandı.",
            source: $this->getId(),
            undo: ['method' => 'undoAdopt', 'args' => [$app->id, $before]],
        );
    }

    public function undoAdopt(int $id, ?string $webRoot, LaravelApps $apps): void
    {
        $app = LaravelApp::with('site')->whereHas('site', fn ($q) => $q->visibleTo(auth()->user()))->find($id);
        if ($app !== null && $app->source === 'existing' && $app->deployments()->doesntExist()) {
            $apps->forget($app, $webRoot);
        }
    }

    public function render(): View
    {
        $apps = LaravelApp::query()
            ->select('laravel_apps.*')
            ->with('site.systemUser', 'latestDeployment')
            ->withSum('workers', 'processes')
            ->withCount('previews')
            // Preview environments are listed under their application.
            ->whereNull('preview_of_id')
            ->when(! auth()->user()->is_admin, fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('laravel_apps.id', auth()->user()->laravelMemberships()->select('laravel_app_id'))
                ->when(auth()->user()->isCustomer(), fn ($q) => $q->orWhereHas('site', fn ($s) => $s->visibleTo(auth()->user())))))
            ->when($this->search !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->whereHas('site', fn ($s) => $s->where('domain', 'like', $term));
            })
            ->join('sites', 'sites.id', '=', 'laravel_apps.site_id')
            ->orderBy('sites.domain')
            ->paginate(25);

        return view('livewire.laravel.index', [
            'apps' => $apps,
            'busy' => $apps->getCollection()->contains(fn (LaravelApp $a) => $a->status->isBusy() || $a->latestDeployment?->status->isBusy()),
            'sites' => $this->installing ? $this->freeSites() : collect(),
            'dbServers' => $this->installing ? collect(app(DatabaseServers::class)->installed())->mapWithKeys(fn ($s) => [$s => app(DatabaseServers::class)->label($s)])->all() : [],
            'dbPrefix' => $this->installing && ($site = $this->freeSites()->firstWhere('id', $this->siteId)) ? Databases::prefix($site->systemUser) : '',
            'canInstall' => auth()->user()->is_admin || auth()->user()->isCustomer(),
        ]);
    }

    /**
     * Active sites without a Laravel application.
     *
     * @return Collection<int, Site>
     */
    private function freeSites(): Collection
    {
        return Site::visibleTo(auth()->user())
            ->with('systemUser')
            ->where('status', SiteStatus::Active)
            ->whereDoesntHave('laravelApp')
            ->whereDoesntHave('preview')
            ->orderBy('domain')
            ->get();
    }

    /**
     * The signed-in customer, or null for everyone else.
     */
    private function customer(): ?Customer
    {
        return auth()->user()->isCustomer() ? auth()->user()->customer : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $git = $this->source === 'git';

        return [
            'siteId' => ['required', Rule::exists('sites', 'id')->where('status', SiteStatus::Active->value), Rule::unique('laravel_apps', 'site_id')],
            'source' => ['required', Rule::in(['create', 'git'])],
            'kit' => [Rule::in(array_keys(LaravelApps::STARTER_KITS))],
            'provider' => [Rule::in(array_keys(self::PROVIDERS))],
            'repository' => ['required_if:source,git', 'nullable', 'max:300', 'regex:'.LaravelApps::REPOSITORY_PATTERN],
            'branch' => ['nullable', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
            'phpVersion' => array_filter([
                'required',
                $this->phpVersions !== [] ? Rule::in($this->phpVersions) : null,
                ! $git ? fn ($attr, $value, $fail) => PhpConstraint::allows(self::NEW_PROJECT_PHP, $value) === false
                    ? $fail('Laravel 13 PHP 8.3 veya üstünü ister.') : null : null,
            ]),
            'database' => ['required', Rule::in(['sqlite', 'existing', 'none', ...(app(DatabaseServers::class)->installed() !== [] ? ['new'] : [])])],
            'newDbServer' => ['required_if:database,new', 'nullable', Rule::in(app(DatabaseServers::class)->installed())],
            'newDbSuffix' => ['required_if:database,new', 'nullable', 'regex:'.Databases::NAME_PATTERN, 'max:30'],
            'dbDriver' => ['required_if:database,existing', Rule::in(array_keys(LaravelApps::DB_DRIVERS))],
            'dbHost' => ['required_if:database,existing', 'nullable', 'max:255', 'regex:/^[A-Za-z0-9.:_\/-]+$/'],
            'dbPort' => ['required_if:database,existing', 'nullable', 'integer', 'between:1,65535'],
            'dbName' => ['required_if:database,existing', 'nullable', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'dbUser' => ['required_if:database,existing', 'nullable', 'max:64'],
            'dbPassword' => ['nullable', 'max:255'],
            'packageManager' => [Rule::in(LaravelApps::PACKAGE_MANAGERS)],
        ];
    }

    /**
     * The repository as Git fetches it: "owner/name" on the chosen host,
     * over SSH with the deploy key when the repository is private.
     */
    private function repositoryUrl(): ?string
    {
        if ($this->source !== 'git') {
            return null;
        }
        $repo = $this->repository;
        $host = self::PROVIDERS[$this->provider][1] ?? 'github.com';
        if (preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) {
            $path = preg_replace('~\.git$~', '', $repo).'.git';

            return $this->private ? "git@{$host}:{$path}" : "https://{$host}/{$path}";
        }
        if ($this->private && preg_match('~^https://([A-Za-z0-9.-]+)/(.+)$~', $repo, $m)) {
            return "git@{$m[1]}:".preg_replace('~\.git$~', '', $m[2]).'.git';
        }

        return $repo;
    }
}
