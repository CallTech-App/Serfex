<?php

namespace App\Livewire\Services;

use App\Jobs\ApplySitePhp;
use App\Livewire\Components\ShowsComponentOperations;
use App\Models\ComponentOperation;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Components\Components;
use App\Services\Php\PhpVersions;
use App\Services\System\SystemServices;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hizmetler (administrators only): the server's system services with
 * their state, uptime and resource use, started, stopped and restarted
 * from the list (a stop or restart that interrupts sites asks first); and
 * the PHP versions, installed and removed like in Yazılım Yönetimi, with
 * the default for new sites and the functions customers' sites run
 * without.
 */
#[Layout('components.layouts.app')]
#[Title('Hizmetler · Servik')]
class ServiceIndex extends Component
{
    use ShowsComponentOperations;

    public const TABS = ['hizmetler' => 'Hizmetler', 'php' => 'PHP sürümleri'];

    #[Url(as: 'sekme', except: 'hizmetler')]
    public string $tab = 'hizmetler';

    /** @var array{unit?: string, id?: string, action: string}|null asked to confirm */
    public ?array $confirming = null;

    /** Why the last action could not be done. */
    public ?string $refused = null;

    public string $defaultVersion = '';

    public string $disabledFunctions = '';

    public function mount(PhpVersions $php): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'hizmetler';
        }
        $this->defaultVersion = (string) $php->default();
        $this->disabledFunctions = implode(', ', $php->disabledFunctions());
        $this->operationId = ComponentOperation::running()->where('component', 'like', 'php%')->latest('id')->value('id');
    }

    /**
     * A service action from the list: asks first when it interrupts
     * something customers use.
     */
    public function act(string $unit, string $action, SystemServices $services): void
    {
        $this->refused = null;
        if (! in_array($action, ['start', 'stop', 'restart'], true)) {
            return;
        }
        if ($action === 'stop' || ($action === 'restart' && $services->affects($unit) !== null)) {
            $this->confirming = ['unit' => $unit, 'action' => $action];

            return;
        }
        $this->run($unit, $action, $services);
    }

    public function confirm(SystemServices $services, Components $components): void
    {
        $ask = $this->confirming;
        $this->confirming = null;
        if ($ask === null) {
            return;
        }
        if (isset($ask['id'])) {
            $result = $components->start($ask['id'], $ask['action'], auth()->user());
            if (is_string($result)) {
                $this->refused = $result;

                return;
            }
            $this->operationId = $result->id;

            return;
        }
        $this->run($ask['unit'], $ask['action'], $services);
    }

    public function undo(string $unit, string $action, SystemServices $services): void
    {
        if (in_array($action, ['start', 'stop'], true)) {
            $this->run($unit, $action, $services, false);
        }
    }

    /**
     * Opens the confirmation for installing, updating or removing a PHP
     * version.
     */
    public function askPhp(string $id, string $action, Components $components): void
    {
        if (! preg_match('/^php\d\.\d$/', $id) || ! in_array($action, ['install', 'update', 'remove'], true) || ! $components->operable($id)) {
            return;
        }
        $this->refused = null;
        $this->confirming = ['id' => $id, 'action' => $action];
    }

    public function saveDefaults(PhpVersions $php): void
    {
        $this->resetErrorBag();
        $installed = $php->installed() ?? [];
        $functions = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($this->disabledFunctions)) ?: []))));
        $this->validate([
            'defaultVersion' => ['required', Rule::in($installed)],
        ], [], ['defaultVersion' => 'varsayılan PHP sürümü']);
        foreach ($functions as $f) {
            if (! preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $f)) {
                $this->addError('disabledFunctions', "\"{$f}\" bir PHP fonksiyon adı değil.");

                return;
            }
        }
        if (count($functions) > 100) {
            $this->addError('disabledFunctions', 'En fazla 100 fonksiyon kapatılabilir.');

            return;
        }
        $before = [$php->default(), $php->disabledFunctions()];
        Setting::put('php.default_version', $this->defaultVersion);
        $changed = $functions !== $before[1];
        Setting::put('php.disabled_functions', $functions);
        $this->disabledFunctions = implode(', ', $functions);
        if ($changed) {
            ApplySitePhp::forCustomers();
        }
        $this->dispatch('toast',
            message: 'Yeni siteler PHP '.$this->defaultVersion.' ile açılacak.'.($changed ? ' Müşteri sitelerinin kapalı fonksiyonları güncelleniyor.' : ''),
            source: $this->getId(),
            undo: ['method' => 'undoDefaults', 'args' => [(string) $before[0], $before[1]]],
        );
    }

    /**
     * @param  list<string>  $functions
     */
    public function undoDefaults(string $version, array $functions, PhpVersions $php): void
    {
        if (in_array($version, $php->installed() ?? [], true)) {
            Setting::put('php.default_version', $version);
            $this->defaultVersion = $version;
        }
        $functions = array_values(array_filter($functions, fn ($f) => is_string($f) && preg_match('/^[a-z_][a-z0-9_]{0,63}$/', $f)));
        if ($functions !== $php->disabledFunctions()) {
            Setting::put('php.disabled_functions', $functions);
            ApplySitePhp::forCustomers();
        }
        $this->disabledFunctions = implode(', ', $functions);
        $this->dispatch('toast', message: 'Önceki ayarlar geri yüklendi.');
    }

    public function render(SystemServices $services, PhpVersions $php, Components $components): View
    {
        $error = null;
        $groups = [];
        $phpRows = [];
        if ($this->tab === 'hizmetler') {
            $list = $services->list($error);
            $groups = $list === null ? null : $services->grouped($list);
        } else {
            $phpRows = $php->rows($error);
        }
        $failed = ($this->tab === 'hizmetler' ? $groups : $phpRows) === null;
        $operation = $this->operationId ? ComponentOperation::find($this->operationId) : null;

        return view('livewire.services.index', [
            'groups' => $groups ?? [],
            'phpRows' => $phpRows ?? [],
            'agentError' => $failed ? ($error ? 'Sunucu ajanı hata verdi: '.$error : 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.') : null,
            'busy' => ComponentOperation::running()->exists(),
            'operation' => $operation,
            'operationName' => $operation ? $components->operationName($operation) : null,
            'confirm' => $this->confirming ? $this->confirmText($services, $components) : null,
            'installed' => array_values(array_map(fn ($r) => $r['number'], array_filter($phpRows ?? [], fn ($r) => $r['installed']))),
        ]);
    }

    /**
     * How long a service has been in its state: "3 gün 4 saat".
     */
    public static function uptime(?string $since): string
    {
        if ($since === null) {
            return '—';
        }

        return Carbon::parse($since)->diffForHumans(null, CarbonInterface::DIFF_ABSOLUTE, false, 2);
    }

    private function run(string $unit, string $action, SystemServices $services, bool $offerUndo = true): void
    {
        $result = $services->act($unit, $action);
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $name = $result['name'] ?? $unit;
        $message = match ($action) {
            'start' => "{$name} başlatıldı.",
            'stop' => "{$name} durduruldu.",
            default => "{$name} yeniden başlatıldı.",
        };
        if (in_array($action, ['start', 'restart'], true) && ($result['state'] ?? '') !== 'active') {
            $this->refused = "{$name} çalışmıyor; ayrıntılar için günlüğüne bakın.";
        }
        $undo = $offerUndo ? ['start' => 'stop', 'stop' => 'start'][$action] ?? null : null;
        $this->dispatch('toast',
            message: $message,
            source: $this->getId(),
            undo: $undo ? ['method' => 'undo', 'args' => [$unit, $undo]] : null,
        );
    }

    /**
     * Title, text and button of the confirmation.
     *
     * @return array{title: string, text: string, button: string, danger: bool}
     */
    private function confirmText(SystemServices $services, Components $components): array
    {
        $ask = $this->confirming;
        if (isset($ask['id'])) {
            $name = $components->name($ask['id']);
            $version = substr($ask['id'], 3);
            $sites = Site::where('php_version', $version)->count();

            return match ($ask['action']) {
                'install' => ['title' => "{$name} kurulsun mu?", 'text' => 'PHP-FPM, komut satırı ve yaygın eklentiler kurulur; Ubuntu\'da olmayan sürümler ondrej/php deposundan gelir. Servik\'in php.ini varsayılanları yazılır. Bir adım başarısız olursa yapılanlar geri alınır.', 'button' => 'Kur', 'danger' => false],
                'update' => ['title' => "{$name} güncellensin mi?", 'text' => 'Yeni sürüm kurulur ve PHP-FPM yeniden başlatılır'.($sites ? "; bu sürümü kullanan {$sites} site birkaç saniye yanıt vermeyebilir." : '.'), 'button' => 'Güncelle', 'danger' => false],
                default => ['title' => "{$name} kaldırılsın mı?", 'text' => 'Paketler kaldırılır; yapılandırma önce /var/lib/servik/backups/components altına yedeklenir.', 'button' => 'Kaldır', 'danger' => true],
            };
        }

        return $services->confirmation($services->find($ask['unit']) ?? ['unit' => $ask['unit']], $ask['action']);
    }
}
