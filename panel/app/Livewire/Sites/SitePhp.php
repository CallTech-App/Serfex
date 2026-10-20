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
use App\Services\Php\PhpVersions;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * PHP ve Web Sunucusu: a site's PHP version, whether Nginx serves it alone
 * (PHP-FPM) or in front of Apache for .htaccess files, its own php.ini
 * values and its PHP-FPM pool. Customers change the PHP settings only when
 * their package allows it, within its memory limit. The change is applied
 * right away; when the server does not accept it the site keeps its
 * previous settings.
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

    /** @var array<string, string> the site's own php.ini values; empty = server's */
    public array $ini = [];

    public string $custom = '';

    public string $pm = 'ondemand';

    public string $maxChildren = '';

    public string $maxRequests = '';

    public string $requestTimeout = '';

    /** Customer sites only: run without the server's disabled functions. */
    public bool $allowFunctions = false;

    /** @var array<string, string> the version's server-wide values, as placeholders */
    public array $serverValues = [];

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
        $this->fillPhp($model->php_settings ?? []);
        $this->readServerValues();
    }

    public function updatedPhpVersion(): void
    {
        $this->readServerValues();
    }

    public function save(AgentClient $agent, Processes $processes, PhpVersions $php): void
    {
        $site = $this->site();
        if ($site === null || $site->status->isBusy()) {
            return;
        }
        $this->resetErrorBag();
        $stacks = $this->apacheInstalled || $site->web_server === Site::APACHE ? array_keys(Site::STACKS) : [Site::NGINX];
        $this->validate([
            'phpVersion' => ['required', Rule::in($this->versions)],
            'webServer' => ['required', Rule::in($stacks)],
        ], [
            'webServer.in' => __('Apache kurulu değil.'),
        ], ['phpVersion' => __('PHP sürümü'), 'webServer' => __('web sunucusu')]);

        $app = $site->laravelApp;
        if ($app?->php_requirement && ! PhpConstraint::allows($app->php_requirement, $this->phpVersion)) {
            $this->addError('phpVersion', __('Laravel uygulaması PHP :php_requirement istiyor; PHP :php_version uymuyor.', ['php_requirement' => $app->php_requirement, 'php_version' => $this->phpVersion]));

            return;
        }
        $settings = $this->canEditPhp($site) ? $this->phpSettings($site) : ($site->php_settings ?? []);
        if ($settings === null) {
            return;
        }
        $before = [$site->php_version, $site->web_server, $site->php_settings ?? []];
        if ($this->phpVersion === $before[0] && $this->webServer === $before[1] && $settings == $before[2]) {
            $this->dispatch('toast', message: __('Değişiklik yok.'));

            return;
        }
        if (! $this->apply($site, $this->phpVersion, $this->webServer, $settings, $agent, $processes)) {
            return;
        }
        $this->dispatch('toast',
            message: __(':domain artık PHP :php_version ve :stack_label ile çalışıyor.', ['domain' => $site->domain, 'php_version' => $site->php_version, 'stack_label' => $site->stackLabel()]),
            source: $this->getId(),
            undo: ['method' => 'undo', 'args' => $before],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function undo(string $php, string $web, array $settings, AgentClient $agent, Processes $processes): void
    {
        $site = $this->site();
        if ($site === null || $site->status->isBusy() || ! array_key_exists($web, Site::STACKS) || ! in_array($php, $this->versions, true)) {
            return;
        }
        if (! $this->canEditPhp($site)) {
            $settings = $site->php_settings ?? [];
        }
        if ($this->apply($site, $php, $web, $settings, $agent, $processes)) {
            $this->phpVersion = $php;
            $this->webServer = $web;
            $this->fillPhp($settings);
            $this->readServerValues();
            $this->dispatch('toast', message: __('Önceki ayarlar geri yüklendi.'));
        }
    }

    /**
     * Stops the site's PHP workers; the next request starts fresh ones.
     */
    public function restartFpm(AgentClient $agent): void
    {
        $site = $this->site();
        if ($site === null || $site->status->isBusy()) {
            return;
        }
        try {
            $agent->call('php.restart', ['domain' => $site->domain], 60);
        } catch (AgentException $e) {
            $this->agentError = __('PHP-FPM yeniden başlatılamadı: :value', ['value' => self::agentMessage($e)]);

            return;
        }
        $this->dispatch('toast', message: __(':domain için PHP-FPM yeniden başlatıldı.', ['domain' => $site->domain]));
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
            'canEdit' => $this->canEditPhp($site),
            'customerSite' => $site->customer() !== null,
            'memoryCap' => $site->customer()?->package?->max_php_memory_mb,
            'maxChildrenCap' => auth()->user()->is_admin ? 1000 : PhpVersions::CUSTOMER_MAX_CHILDREN,
        ])->title(__('PHP ve Web Sunucusu · :domain · Servik', ['domain' => $site->domain]));
    }

    /**
     * Writes the site's configuration with the new settings; when the
     * server refuses them the site keeps (and the panel shows) the old.
     */
    /**
     * @param  array<string, mixed>  $settings
     */
    private function apply(Site $site, string $php, string $web, array $settings, AgentClient $agent, Processes $processes): bool
    {
        $before = ['php_version' => $site->php_version, 'web_server' => $site->web_server, 'php_settings' => $site->php_settings];
        $site->fill(['php_version' => $php, 'web_server' => $web, 'php_settings' => $settings === [] ? null : $settings]);
        try {
            $agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->fill($before);
            $this->phpVersion = $before['php_version'];
            $this->webServer = $before['web_server'];
            $this->fillPhp($before['php_settings'] ?? []);
            $this->agentError = __('Değişiklik uygulanamadı, site önceki ayarlarıyla çalışıyor: :value', ['value' => self::agentMessage($e)]);

            return false;
        }
        $site->save();
        $this->agentError = null;
        if ($before['php_version'] !== $php && $site->laravelApp?->status === LaravelAppStatus::Active) {
            // Queue workers, the scheduler and processes run this PHP too.
            try {
                $processes->apply($site->laravelApp);
            } catch (AgentException $e) {
                $this->agentError = __('Site yeni PHP ile çalışıyor ama Laravel süreçleri güncellenemedi: :value', ['value' => self::agentMessage($e)]);
            }
        }

        return true;
    }

    private function canEditPhp(Site $site): bool
    {
        $user = auth()->user();

        return $user->is_admin || ($user->allows('php') && (bool) $site->customer()?->package?->allows('php'));
    }

    /**
     * The form as php_settings, or null after reporting what is wrong.
     *
     * @return array<string, mixed>|null
     */
    private function phpSettings(Site $site): ?array
    {
        $admin = auth()->user()->is_admin;
        $customer = $site->customer();
        $cap = $customer?->package?->max_php_memory_mb;
        $childCap = $admin ? 1000 : PhpVersions::CUSTOMER_MAX_CHILDREN;
        $ini = [];
        foreach (PhpVersions::SITE_INI as $key => [$label, $type]) {
            $value = trim((string) ($this->ini[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $error = match ($type) {
                'size' => preg_match(PhpVersions::SIZE_PATTERN, $value) ? null : __('128M, 1G ya da -1 gibi bir değer girin.'),
                'onoff' => in_array($value, ['On', 'Off'], true) ? null : __('On ya da Off seçin.'),
                default => preg_match('/^\d{1,7}$/', $value) ? null : __('Bir sayı girin.'),
            };
            if ($error === null && $key === 'memory_limit' && $cap !== null) {
                $mb = PhpVersions::megabytes($value);
                if ($mb === null || $mb > $cap) {
                    $error = __('Paketiniz en fazla :capM\'ye izin veriyor.', ['cap' => $cap]);
                }
            }
            if ($error !== null) {
                $this->addError('ini.'.$key, $error);

                continue;
            }
            $ini[$key] = $type === 'size' ? strtoupper($value) : $value;
        }
        $post = PhpVersions::megabytes($ini['post_max_size'] ?? $this->serverValues['post_max_size'] ?? null);
        $upload = PhpVersions::megabytes($ini['upload_max_filesize'] ?? $this->serverValues['upload_max_filesize'] ?? null);
        if ($post !== null && $upload !== null && $upload > $post && ! $this->getErrorBag()->has('ini.post_max_size')) {
            $this->addError('ini.post_max_size', __('Form gönderimi sınırı, dosya yükleme sınırından küçük olamaz.'));
        }
        $custom = trim($this->custom);
        if ($custom !== '' && is_string($parsed = PhpVersions::parseCustom($custom, ! $admin && $customer !== null))) {
            $this->addError('custom', $parsed);
        }
        if (! array_key_exists($this->pm, PhpVersions::PM)) {
            $this->addError('pm', __('Geçersiz seçim.'));
        }
        foreach (['maxChildren' => [1, $childCap], 'maxRequests' => [0, 1000000], 'requestTimeout' => [0, 86400]] as $field => [$min, $max]) {
            $v = trim($this->{$field});
            if ($v !== '' && (! preg_match('/^\d{1,7}$/', $v) || (int) $v < $min || (int) $v > $max)) {
                $this->addError($field, __(':min ile :max arasında bir sayı girin.', ['min' => $min, 'max' => $max]));
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }
        $old = $site->php_settings ?? [];
        $out = array_filter([
            'ini' => $ini,
            'custom' => $custom,
            'pm' => $this->pm === 'ondemand' ? null : $this->pm,
            'max_children' => $this->maxChildren === '' ? null : (int) $this->maxChildren,
            'max_requests' => $this->maxRequests === '' ? null : (int) $this->maxRequests,
            'request_timeout' => $this->requestTimeout === '' ? null : (int) $this->requestTimeout,
            // Only an administrator gives a customer's site the functions.
            'allow_functions' => $customer !== null && ($admin ? $this->allowFunctions : (bool) ($old['allow_functions'] ?? false)),
        ], fn ($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function fillPhp(array $s): void
    {
        $this->ini = [];
        foreach (PhpVersions::SITE_INI as $key => $_) {
            $this->ini[$key] = (string) ($s['ini'][$key] ?? '');
        }
        $this->custom = (string) ($s['custom'] ?? '');
        $this->pm = (string) ($s['pm'] ?? 'ondemand');
        $this->maxChildren = isset($s['max_children']) ? (string) $s['max_children'] : '';
        $this->maxRequests = isset($s['max_requests']) ? (string) $s['max_requests'] : '';
        $this->requestTimeout = isset($s['request_timeout']) ? (string) $s['request_timeout'] : '';
        $this->allowFunctions = (bool) ($s['allow_functions'] ?? false);
    }

    /**
     * The chosen version's server-wide values, shown where the site keeps
     * them.
     */
    private function readServerValues(): void
    {
        if (! preg_match('/^\d\.\d$/', $this->phpVersion)) {
            return;
        }
        try {
            $info = app(PhpVersions::class)->info($this->phpVersion);
        } catch (AgentException) {
            $this->serverValues = [];

            return;
        }
        app(PhpVersions::class)->remember($info);
        $this->serverValues = array_map('strval', array_intersect_key((array) ($info['values'] ?? []), PhpVersions::SITE_INI));
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'certificate', 'laravelApp')->find($this->siteId);
    }

    private static function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.')
            : $e->reason;
    }
}
