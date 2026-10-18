<?php

namespace App\Livewire\Laravel;

use App\Enums\SiteStatus;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\PhpConstraint;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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
        $site = Site::find((int) request()->query('site'));
        if ($site !== null && $site->laravelApp()->doesntExist()) {
            $this->openInstall(app(AgentClient::class), $site->id);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openInstall(AgentClient $agent, ?int $siteId = null): void
    {
        $this->reset('step', 'siteId', 'source', 'kit', 'provider', 'repository', 'private', 'branch', 'phpVersion', 'database', 'dbDriver', 'dbHost',
            'dbPort', 'dbName', 'dbUser', 'dbPassword', 'frontend', 'packageManager', 'worker', 'scheduler', 'deployKey', 'agentError');
        $this->resetValidation();
        $this->installing = true;
        try {
            $this->phpVersions = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            $this->phpVersions = [];
            $this->agentError = 'Sunucu ajanına ulaşılamıyor: '.($e->reason ?: $e->getMessage());
        }
        $this->siteId = $siteId ?? $this->freeSites()->first()?->id;
        $this->updatedSiteId();
    }

    public function updatedSiteId(): void
    {
        $site = $this->siteId ? Site::find($this->siteId) : null;
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
    public function install(AgentClient $agent, LaravelApps $apps): void
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
        ]);
        $site = Site::with('systemUser')->findOrFail($this->siteId);

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

        $app = $apps->install($site, [
            'source' => $this->source,
            'kit' => $this->kit,
            'repository' => $this->repositoryUrl(),
            'branch' => $this->branch,
            'php_version' => $this->phpVersion,
            'database' => $this->database,
            'db' => ['driver' => $this->dbDriver, 'host' => $this->dbHost, 'port' => $this->dbPort, 'database' => $this->dbName, 'username' => $this->dbUser, 'password' => $this->dbPassword],
            'frontend' => $this->frontend,
            'package_manager' => $this->packageManager,
            'worker' => $this->worker,
            'scheduler' => $this->scheduler,
        ], auth()->user());
        if ($this->deployKey !== null) {
            $app->update(['deploy_key' => $this->deployKey]);
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
    public function adopt(int $index, LaravelApps $apps): void
    {
        $f = $this->found[$index] ?? null;
        $site = $f ? Site::find($f['site_id']) : null;
        if ($site === null || $site->laravelApp()->exists()) {
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
        $app = LaravelApp::with('site')->find($id);
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
        ]);
    }

    /**
     * Active sites without a Laravel application.
     *
     * @return Collection<int, Site>
     */
    private function freeSites(): Collection
    {
        return Site::with('systemUser')
            ->where('status', SiteStatus::Active)
            ->whereDoesntHave('laravelApp')
            ->orderBy('domain')
            ->get();
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
            'database' => ['required', Rule::in(['sqlite', 'existing', 'none'])],
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
