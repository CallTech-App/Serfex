<?php

namespace App\Livewire\Laravel;

use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Models\LaravelPreview;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Previews;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A Laravel application's page: a left menu of sections, the deploy
 * window, and the install log while it is being installed.
 */
#[Layout('components.layouts.app')]
class LaravelShow extends Component
{
    use GuardsAppCalls;
    use ReadsRunLogs;

    /**
     * Menu: group => [key => [label, component, ability needed to open it]].
     */
    public const SECTIONS = [
        'Uygulama' => [
            'overview' => ['Genel bakış', 'laravel.sections.overview', 'view'],
            'deployments' => ['Dağıtımlar', 'laravel.sections.deployments', 'view'],
            'previews' => ['Önizleme ortamları', 'laravel.sections.previews', 'view'],
            'environment' => ['Ortam (.env)', 'laravel.sections.environment', 'operate'],
        ],
        'Çalıştırma' => [
            'commands' => ['Komutlar', 'laravel.sections.commands', 'view'],
            'queues' => ['Kuyruklar', 'laravel.sections.queues', 'view'],
            'scheduler' => ['Zamanlayıcı', 'laravel.sections.scheduler', 'view'],
            'processes' => ['Süreçler', 'laravel.sections.processes', 'view'],
        ],
        'Bakım' => [
            'logs' => ['Günlükler', 'laravel.sections.logs', 'view'],
            'maintenance' => ['Bakım modu', 'laravel.sections.maintenance', 'view'],
            'backups' => ['Yedekler', 'laravel.sections.backups', 'operate'],
            'access' => ['Erişim ve API', 'laravel.sections.access', 'view'],
        ],
    ];

    /** Sections a preview environment does not have. */
    public const NOT_IN_PREVIEWS = ['previews', 'backups', 'access'];

    protected const VIEW_METHODS = ['show', 'refreshStatus', 'dismissAdmin'];

    protected const MANAGE_METHODS = ['openRemove', 'remove'];

    #[Locked]
    public int $appId;

    #[Url(as: 'section', except: 'overview')]
    public string $section = 'overview';

    public bool $deploying = false;

    public string $ref = '';

    public bool $migrate = true;

    public bool $frontend = true;

    public bool $removing = false;

    public bool $removeFiles = true;

    public string $confirmDomain = '';

    /**
     * The first administrator an application template created, shown
     * once after the install started: name, email, password, template.
     *
     * @var array{name: string, email: string, password: string, template: string}|null
     */
    public ?array $adminShown = null;

    /**
     * Takes the id rather than a bound model, like SiteShow: a removed
     * application then sends the browser back to the list instead of a 404.
     */
    public function mount(int $app): void
    {
        $model = LaravelApp::findOrFail($app);
        abort_unless(auth()->user()->canOnApp($model, 'view'), 403);
        $this->appId = $model->id;
        if ($this->component($this->section) === null) {
            $this->section = 'overview';
        }
        $admin = session('template_admin');
        if (is_string($admin)) {
            try {
                $admin = Crypt::decrypt($admin);
            } catch (DecryptException) {
                $admin = null;
            }
            if (is_array($admin) && ($admin['app'] ?? null) === $model->id) {
                $this->adminShown = array_intersect_key($admin, array_flip(['name', 'email', 'password', 'template']));
            }
        }
    }

    public function dismissAdmin(): void
    {
        $this->adminShown = null;
    }

    #[On('show-section')]
    public function show(string $section): void
    {
        if ($this->component($section) !== null) {
            $this->section = $section;
        }
    }

    /**
     * Polled while the application is installed or removed.
     */
    public function refreshStatus(): void
    {
        if ($this->app() === null) {
            session()->flash('toast', __('Laravel uygulaması kaldırıldı.'));
            $this->redirectRoute('laravel.index', navigate: true);
        }
    }

    #[On('open-deploy')]
    public function openDeploy(): void
    {
        $app = $this->app();
        if ($app === null || ! $app->canDeploy()) {
            return;
        }
        $this->ref = (string) $app->branch;
        $this->migrate = $app->migrate;
        $this->frontend = $app->frontend;
        $this->resetValidation();
        $this->deploying = true;
    }

    public function deploy(LaravelApps $apps): void
    {
        $app = $this->app();
        if ($app === null || ! $app->canDeploy()) {
            $this->deploying = false;

            return;
        }
        $this->ref = trim($this->ref);
        $this->validate([
            'ref' => ['nullable', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
        ], ['ref.regex' => __('Dal veya etiket adı geçersiz.'), 'ref.not_regex' => __('Dal veya etiket adı geçersiz.')]);
        $deployment = $apps->deploy($app, $this->ref, auth()->user(), $this->migrate, $this->frontend);
        $this->deploying = false;
        $this->section = 'deployments';
        $this->dispatch('show-deploy-log', id: $deployment->id);
    }

    public function retryInstall(LaravelApps $apps): void
    {
        $app = $this->app();
        if ($app !== null && $app->status === LaravelAppStatus::Failed && $app->current_release === null && $app->deployments()->where('kind', 'install')->exists()) {
            $apps->retryInstall($app, auth()->user());
        }
    }

    public function openRemove(): void
    {
        $this->reset('confirmDomain', 'removeFiles');
        $this->resetValidation();
        $this->removing = true;
    }

    public function remove(LaravelApps $apps): void
    {
        $app = $this->app();
        if ($app === null || $app->status->isBusy()) {
            return;
        }
        if ($this->confirmDomain !== $app->site->domain) {
            $this->addError('confirmDomain', __('Alan adını tam olarak yazın.'));

            return;
        }
        $preview = LaravelPreview::where('preview_app_id', $app->id)->first();
        if ($preview !== null) {
            app(Previews::class)->delete($preview);
            session()->flash('toast', __(':domain siliniyor.', ['domain' => $app->site->domain]));
            $this->redirectRoute('laravel.show', ['app' => $app->preview_of_id, 'section' => 'previews'], navigate: true);

            return;
        }
        $apps->remove($app, $this->removeFiles);
        $this->removing = false;
    }

    public function render(): View
    {
        $app = $this->app();
        if ($app === null) {
            return view('livewire.sites.gone');
        }
        if ($this->component($this->section) === null) {
            $this->section = 'overview';
        }
        $install = $app->status === LaravelAppStatus::Active ? null : $app->deployments()->where('kind', 'install')->first();

        return view('livewire.laravel.show', [
            'app' => $app,
            'sections' => $this->sections($app),
            'site' => $app->site,
            'install' => $install,
            'component' => $this->component($this->section),
            'running' => $app->deployments()->whereIn('status', ['queued', 'running'])->first(),
        ])->title($app->site->domain.' · Laravel · Serfex');
    }

    protected function app(): ?LaravelApp
    {
        $app = LaravelApp::with('site.systemUser', 'site.certificate', 'previewOf.site', 'template')->find($this->appId);
        abort_unless($app === null || auth()->user()->canOnApp($app, 'view'), 403);

        return $app;
    }

    /**
     * The menu the current account sees for $app.
     *
     * @return array<string, array<string, array{0: string, 1: string, 2: string}>>
     */
    private function sections(LaravelApp $app): array
    {
        $user = auth()->user();
        $menu = [];
        foreach (self::SECTIONS as $group => $items) {
            foreach ($items as $key => $item) {
                if ($user->canOnApp($app, $item[2]) && ! ($app->isPreview() && in_array($key, self::NOT_IN_PREVIEWS, true))
                    && ! ($key === 'commands' && ! $user->mayUseOnApp($app, 'shell'))) {
                    $menu[$group][$key] = $item;
                }
            }
        }

        return $menu;
    }

    /**
     * The component of $section, when the current account may open it.
     */
    private function component(string $section): ?string
    {
        $app = LaravelApp::find($this->appId);
        if ($app === null) {
            return null;
        }
        foreach ($this->sections($app) as $items) {
            if (isset($items[$section])) {
                return $items[$section][1];
            }
        }

        return null;
    }
}
