<?php

namespace App\Livewire\Services;

use App\Livewire\Components\ShowsComponentOperations;
use App\Models\ComponentOperation;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use App\Services\Php\PhpVersions;
use App\Services\System\SystemServices;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One PHP version (administrators only): the server-wide php.ini defaults
 * its sites start from, and its extensions switched on or off, the
 * optional ones installed or removed. Every change can be undone.
 */
#[Layout('components.layouts.app')]
class PhpVersionShow extends Component
{
    use ShowsComponentOperations;

    public const TABS = ['ayarlar' => 'php.ini varsayılanları', 'eklentiler' => 'Eklentiler'];

    public string $version;

    #[Url(as: 'sekme', except: 'ayarlar')]
    public string $tab = 'ayarlar';

    /** @var array<string, string> the form, by field() */
    public array $values = [];

    /** @var array<string, string> as the server has them */
    public array $current = [];

    /** @var array<string, string> Servik's defaults */
    public array $defaults = [];

    public bool $managed = true;

    /** @var list<array{name: string, enabled: bool, optional?: bool, installed: bool}> */
    public array $extensions = [];

    /** @var array{name: string, action: string}|null */
    public ?array $confirming = null;

    public ?string $agentError = null;

    public ?string $refused = null;

    public function mount(string $version, PhpVersions $php): void
    {
        $this->version = $version;
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'ayarlar';
        }
        $installed = $php->installed();
        if ($installed !== null && ! in_array($version, $installed, true)) {
            abort(404);
        }
        $this->operationId = ComponentOperation::running()->where('component', 'php'.$version)->latest('id')->value('id');
        $this->load($php);
    }

    public function save(AgentClient $agent, PhpVersions $php): void
    {
        $this->refused = null;
        $this->resetErrorBag();
        $values = [];
        foreach (PhpVersions::INI as $key => [$label, $type]) {
            $value = trim((string) ($this->values[self::field($key)] ?? ''));
            $error = match (true) {
                $value === '' => __('Boş bırakılamaz.'),
                $type === 'size' && ! preg_match(PhpVersions::SIZE_PATTERN, $value) => __('128M, 1G ya da -1 gibi bir değer girin.'),
                in_array($type, ['seconds', 'int'], true) && ! preg_match('/^\d{1,7}$/', $value) => __('Bir sayı girin.'),
                $type === 'onoff' && ! in_array($value, ['On', 'Off'], true) => __('On ya da Off seçin.'),
                $type === 'flag' && ! in_array($value, ['0', '1'], true) => __('Açık ya da kapalı seçin.'),
                $type === 'text' && ! preg_match(PhpVersions::VALUE_PATTERN, $value) => __('Geçersiz karakter.'),
                default => null,
            };
            if ($error !== null) {
                $this->addError('values.'.self::field($key), $error);
            }
            $values[$key] = $type === 'size' ? strtoupper($value) : $value;
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        if (($post = PhpVersions::megabytes($values['post_max_size'])) !== null && ($upload = PhpVersions::megabytes($values['upload_max_filesize'])) !== null && $upload > $post) {
            $this->addError('values.post_max_size', __('Form gönderimi sınırı, dosya yükleme sınırından küçük olamaz.'));

            return;
        }
        if ($values === $this->current && $this->managed) {
            $this->dispatch('toast', message: __('Değişiklik yok.'));

            return;
        }
        $before = $this->current;
        if (! $this->apply($agent, $php, $values)) {
            return;
        }
        $this->dispatch('toast',
            message: __('PHP :version yeni varsayılanlarla çalışıyor.', ['version' => $this->version]),
            source: $this->getId(),
            undo: ['method' => 'undoSettings', 'args' => [$before]],
        );
    }

    /**
     * Fills the form with Servik's defaults; saving applies them.
     */
    public function resetToDefaults(): void
    {
        foreach (PhpVersions::INI as $key => $_) {
            if (isset($this->defaults[$key])) {
                $this->values[self::field($key)] = $this->defaults[$key];
            }
        }
        $this->resetValidation();
    }

    /**
     * @param  array<string, string>  $before
     */
    public function undoSettings(array $before, AgentClient $agent, PhpVersions $php): void
    {
        $values = array_intersect_key(array_map('strval', $before), PhpVersions::INI);
        if ($this->apply($agent, $php, $values)) {
            $this->dispatch('toast', message: __('Önceki ayarlar geri yüklendi.'));
        }
    }

    /**
     * Switches an installed extension on or off; switching one off while
     * sites run this version asks first.
     */
    public function toggle(string $name, AgentClient $agent, PhpVersions $php): void
    {
        $ext = collect($this->extensions)->firstWhere('name', $name);
        if ($ext === null || ! $ext['installed']) {
            return;
        }
        if ($ext['enabled'] && Site::where('php_version', $this->version)->exists()) {
            $this->confirming = ['name' => $name, 'action' => 'disable'];

            return;
        }
        $this->setExtension($name, ! $ext['enabled'], $agent, $php);
    }

    /**
     * Asks before installing or removing an optional extension.
     */
    public function askExtension(string $name, string $action): void
    {
        $ext = collect($this->extensions)->firstWhere('name', $name);
        if ($ext === null || ! ($ext['optional'] ?? false) || ! in_array($action, ['install', 'remove'], true)) {
            return;
        }
        $this->confirming = ['name' => $name, 'action' => $action];
    }

    public function confirm(AgentClient $agent, PhpVersions $php): void
    {
        $ask = $this->confirming;
        $this->confirming = null;
        $this->refused = null;
        if ($ask === null) {
            return;
        }
        if ($ask['action'] === 'disable') {
            $this->setExtension($ask['name'], false, $agent, $php);

            return;
        }
        $result = $php->startExtension($this->version, $ask['name'], $ask['action'] === 'remove', auth()->user());
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->operationId = $result->id;
    }

    public function undoExtension(string $name, bool $enabled, AgentClient $agent, PhpVersions $php): void
    {
        $this->setExtension($name, $enabled, $agent, $php, false);
    }

    public function closeOperation(): void
    {
        $this->operationId = null;
        $this->load(app(PhpVersions::class));
    }

    public function render(Components $components, SystemServices $services): View
    {
        $operation = $this->operationId ? ComponentOperation::find($this->operationId) : null;
        $nginx = $this->tab === 'ayarlar' ? $services->settings('nginx', 'nginx') : null;
        $bodyLimit = is_array($nginx) ? ($nginx['client_max_body_mb'] ?? null) : null;
        $post = PhpVersions::megabytes($this->current['post_max_size'] ?? null);

        return view('livewire.services.php', [
            'sites' => Site::where('php_version', $this->version)->orderBy('domain')->pluck('domain')->all(),
            'operation' => $operation,
            'operationName' => $operation ? $components->operationName($operation) : null,
            'busy' => ComponentOperation::running()->exists(),
            'bodyLimit' => $bodyLimit !== null && $post !== null && $post > $bodyLimit ? (int) $bodyLimit : null,
            'confirm' => $this->confirming ? $this->confirmText() : null,
        ])->title(__('PHP :version · Hizmetler · Servik', ['version' => $this->version]));
    }

    private function load(PhpVersions $php): void
    {
        try {
            $info = $php->info($this->version);
        } catch (AgentException $e) {
            $this->agentError = SystemServices::message($e);

            return;
        }
        $this->agentError = null;
        $php->remember($info);
        $this->useInfo($info);
    }

    /**
     * The form field of an INI setting: Livewire would read the dot in
     * date.timezone as a nested array.
     */
    public static function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function useInfo(array $info): void
    {
        $values = (array) ($info['values'] ?? []);
        $this->current = [];
        foreach (PhpVersions::INI as $key => $_) {
            $this->current[$key] = (string) ($values[$key] ?? ($info['defaults'][$key] ?? ''));
        }
        $this->values = [];
        foreach ($this->current as $key => $value) {
            $this->values[self::field($key)] = $value;
        }
        $this->defaults = array_map('strval', (array) ($info['defaults'] ?? []));
        $this->managed = (bool) ($info['managed'] ?? false);
        $this->extensions = array_values((array) ($info['extensions'] ?? []));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function apply(AgentClient $agent, PhpVersions $php, array $values): bool
    {
        try {
            $info = (array) $agent->call('php.settings.apply', ['version' => $this->version, 'values' => $values], 120);
        } catch (AgentException $e) {
            $this->refused = __('Ayarlar uygulanamadı, PHP önceki ayarlarıyla çalışıyor: :value', ['value' => SystemServices::message($e)]);

            return false;
        }
        $php->remember($info);
        $this->useInfo($info);

        return true;
    }

    private function setExtension(string $name, bool $enabled, AgentClient $agent, PhpVersions $php, bool $offerUndo = true): void
    {
        try {
            $info = (array) $agent->call('php.extension.set', ['version' => $this->version, 'name' => $name, 'enabled' => $enabled], 120);
        } catch (AgentException $e) {
            $this->refused = ($enabled ? __('Eklenti açılamadı: ') : __('Eklenti kapatılamadı: ')).SystemServices::message($e);

            return;
        }
        $this->useInfo($info);
        $this->dispatch('toast',
            message: $enabled ? __(':name eklentisi PHP :version için açıldı.', ['name' => $name, 'version' => $this->version]) : __(':name eklentisi PHP :version için kapatıldı.', ['name' => $name, 'version' => $this->version]),
            source: $this->getId(),
            undo: $offerUndo ? ['method' => 'undoExtension', 'args' => [$name, ! $enabled]] : null,
        );
    }

    /**
     * @return array{title: string, text: string, button: string, danger: bool}
     */
    private function confirmText(): array
    {
        $name = $this->confirming['name'];
        $sites = Site::where('php_version', $this->version)->count();

        return match ($this->confirming['action']) {
            'disable' => ['title' => __(':name kapatılsın mı?', ['name' => $name]), 'text' => __('PHP :version kullanan :sites site bu eklenti olmadan çalışır; ona ihtiyaç duyan sayfalar hata verebilir. PHP-FPM kesintisiz yeniden yüklenir.', ['version' => $this->version, 'sites' => $sites]), 'button' => __('Kapat'), 'danger' => true],
            'install' => ['title' => __(':name kurulsun mu?', ['name' => $name]), 'text' => __('php:version-:name paketi kurulur, eklenti açılır ve PHP-FPM yeniden yüklenir. Bir adım başarısız olursa geri alınır.', ['version' => $this->version, 'name' => $name]), 'button' => __('Kur'), 'danger' => false],
            default => ['title' => __(':name kaldırılsın mı?', ['name' => $name]), 'text' => $sites ? __('php:version-:name paketi kaldırılır; PHP :version kullanan :sites site bu eklenti olmadan çalışır.', ['version' => $this->version, 'name' => $name, 'sites' => $sites]) : __('php:version-:name paketi kaldırılır.', ['version' => $this->version, 'name' => $name]), 'button' => __('Kaldır'), 'danger' => true],
        };
    }
}
