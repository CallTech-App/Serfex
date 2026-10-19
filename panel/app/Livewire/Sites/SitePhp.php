<?php

namespace App\Livewire\Sites;

use App\Enums\LaravelAppStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Laravel\PhpConstraint;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PHP ve Web Sunucusu: a site's PHP version and whether Nginx serves it
 * alone (PHP-FPM) or in front of Apache, for .htaccess files. The change
 * is applied right away; when the server does not accept it the site
 * keeps its previous settings.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SitePhp extends Component
{
    public int $siteId;

    public string $phpVersion = '';

    public string $webServer = Site::NGINX;

    /** @var list<string> PHP versions the site may use */
    public array $versions = [];

    public bool $apacheInstalled = false;

    public ?string $agentError = null;

    public function mount(int $site, AgentClient $agent, Components $components): void
    {
        $model = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $model->id;
        $this->phpVersion = $model->php_version;
        $this->webServer = $model->web_server;

        try {
            $installed = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            $installed = [$model->php_version];
            $this->agentError = self::agentMessage($e);
        }
        $package = $model->customer()?->package;
        $this->versions = $package ? $package->phpVersionsFrom($installed) : $installed;
        if (! in_array($model->php_version, $this->versions, true)) {
            $this->versions[] = $model->php_version;
        }
        usort($this->versions, fn ($a, $b) => version_compare($b, $a));
        $components->statuses();
        $this->apacheInstalled = $components->isInstalled('apache');
    }

    public function save(AgentClient $agent, Processes $processes): void
    {
        $site = $this->site();
        if ($site === null || $site->status->isBusy()) {
            return;
        }
        $stacks = $this->apacheInstalled || $site->web_server === Site::APACHE ? array_keys(Site::STACKS) : [Site::NGINX];
        $this->validate([
            'phpVersion' => ['required', Rule::in($this->versions)],
            'webServer' => ['required', Rule::in($stacks)],
        ], [
            'webServer.in' => 'Apache kurulu değil.',
        ], ['phpVersion' => 'PHP sürümü', 'webServer' => 'web sunucusu']);

        $app = $site->laravelApp;
        if ($app?->php_requirement && ! PhpConstraint::allows($app->php_requirement, $this->phpVersion)) {
            $this->addError('phpVersion', "Laravel uygulaması PHP {$app->php_requirement} istiyor; PHP {$this->phpVersion} uymuyor.");

            return;
        }
        if ($this->phpVersion === $site->php_version && $this->webServer === $site->web_server) {
            $this->dispatch('toast', message: 'Değişiklik yok.');

            return;
        }
        $before = [$site->php_version, $site->web_server];
        if (! $this->apply($site, $this->phpVersion, $this->webServer, $agent, $processes)) {
            return;
        }
        $this->dispatch('toast',
            message: "{$site->domain} artık PHP {$site->php_version} ve {$site->stackLabel()} ile çalışıyor.",
            source: $this->getId(),
            undo: ['method' => 'undo', 'args' => $before],
        );
    }

    public function undo(string $php, string $web, AgentClient $agent, Processes $processes): void
    {
        $site = $this->site();
        if ($site === null || $site->status->isBusy() || ! array_key_exists($web, Site::STACKS) || ! in_array($php, $this->versions, true)) {
            return;
        }
        if ($this->apply($site, $php, $web, $agent, $processes)) {
            $this->phpVersion = $php;
            $this->webServer = $web;
            $this->dispatch('toast', message: 'Önceki ayarlar geri yüklendi.');
        }
    }

    public function render(): View
    {
        $site = $this->site();
        if ($site === null) {
            return view('livewire.sites.gone');
        }

        return view('livewire.sites.php', [
            'site' => $site,
            'octane' => $site->laravelApp?->processes()->where('kind', 'octane')->where('enabled', true)->exists() ?? false,
        ])->title('PHP ve Web Sunucusu · '.$site->domain.' · Servik');
    }

    /**
     * Writes the site's configuration with the new settings; when the
     * server refuses them the site keeps (and the panel shows) the old.
     */
    private function apply(Site $site, string $php, string $web, AgentClient $agent, Processes $processes): bool
    {
        $before = ['php_version' => $site->php_version, 'web_server' => $site->web_server];
        $site->fill(['php_version' => $php, 'web_server' => $web]);
        try {
            $agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->fill($before);
            $this->phpVersion = $before['php_version'];
            $this->webServer = $before['web_server'];
            $this->agentError = 'Değişiklik uygulanamadı, site önceki ayarlarıyla çalışıyor: '.self::agentMessage($e);

            return false;
        }
        $site->save();
        $this->agentError = null;
        if ($before['php_version'] !== $php && $site->laravelApp?->status === LaravelAppStatus::Active) {
            // Queue workers, the scheduler and processes run this PHP too.
            try {
                $processes->apply($site->laravelApp);
            } catch (AgentException $e) {
                $this->agentError = 'Site yeni PHP ile çalışıyor ama Laravel süreçleri güncellenemedi: '.self::agentMessage($e);
            }
        }

        return true;
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'certificate', 'laravelApp')->find($this->siteId);
    }

    private static function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.'
            : $e->reason;
    }
}
