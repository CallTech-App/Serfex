<?php

namespace App\Livewire\Laravel;

use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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
    use ReadsRunLogs;

    /**
     * Menu: group => [key => [label, component]]; a null component is a
     * section that comes in a later version.
     */
    public const SECTIONS = [
        'Uygulama' => [
            'genel' => ['Genel bakış', 'laravel.sections.overview'],
            'dagitimlar' => ['Dağıtımlar', 'laravel.sections.deployments'],
            'onizleme' => ['Önizleme ortamları', null],
            'ortam' => ['Ortam (.env)', 'laravel.sections.environment'],
        ],
        'Çalıştırma' => [
            'komutlar' => ['Komutlar', 'laravel.sections.commands'],
            'kuyruklar' => ['Kuyruklar', 'laravel.sections.queues'],
            'zamanlayici' => ['Zamanlayıcı', 'laravel.sections.scheduler'],
            'surecler' => ['Süreçler', null],
        ],
        'Bakım' => [
            'gunlukler' => ['Günlükler', 'laravel.sections.logs'],
            'bakim' => ['Bakım modu', 'laravel.sections.maintenance'],
            'yedekler' => ['Yedekler', null],
            'erisim' => ['Erişim ve API', null],
        ],
    ];

    public int $appId;

    #[Url(as: 'bolum', except: 'genel')]
    public string $section = 'genel';

    public bool $deploying = false;

    public string $ref = '';

    public bool $migrate = true;

    public bool $frontend = true;

    public bool $removing = false;

    public bool $removeFiles = true;

    public string $confirmDomain = '';

    /**
     * Takes the id rather than a bound model, like SiteShow: a removed
     * application then sends the browser back to the list instead of a 404.
     */
    public function mount(int $app): void
    {
        $this->appId = LaravelApp::findOrFail($app)->id;
        if ($this->component($this->section) === null) {
            $this->section = 'genel';
        }
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
            session()->flash('toast', 'Laravel uygulaması kaldırıldı.');
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
        ], ['ref.regex' => 'Dal veya etiket adı geçersiz.', 'ref.not_regex' => 'Dal veya etiket adı geçersiz.']);
        $deployment = $apps->deploy($app, $this->ref, auth()->user(), $this->migrate, $this->frontend);
        $this->deploying = false;
        $this->section = 'dagitimlar';
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
            $this->addError('confirmDomain', 'Alan adını tam olarak yazın.');

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
        $install = $app->status === LaravelAppStatus::Active ? null : $app->deployments()->where('kind', 'install')->first();

        return view('livewire.laravel.show', [
            'app' => $app,
            'site' => $app->site,
            'install' => $install,
            'component' => $this->component($this->section),
            'running' => $app->deployments()->whereIn('status', ['queued', 'running'])->first(),
        ])->title($app->site->domain.' · Laravel · Servik');
    }

    protected function app(): ?LaravelApp
    {
        return LaravelApp::with('site.systemUser', 'site.certificate')->find($this->appId);
    }

    private function component(string $section): ?string
    {
        foreach (self::SECTIONS as $items) {
            if (isset($items[$section])) {
                return $items[$section][1];
            }
        }

        return null;
    }
}
