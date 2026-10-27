<?php

namespace App\Livewire\Components;

use App\Jobs\SetupMailServer;
use App\Models\ComponentOperation;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Databases\DatabaseServers;
use App\Services\Mail\MailSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Yazılım Yönetimi (administrators only): install, update and remove the
 * web server, PHP versions, database servers and other software, with a
 * confirmation first and the agent's steps shown as they run. A failed
 * install is rolled back by the agent; a component in use cannot be
 * removed and the page says what uses it.
 */
#[Layout('components.layouts.app')]
#[Title('Yazılım Yönetimi · Serfex')]
class ComponentIndex extends Component
{
    use ShowsComponentOperations;

    #[Url(as: 'sekme', except: 'web')]
    public string $tab = 'web';

    /** @var array{id: string, action: string}|null asked to confirm */
    public ?array $confirming = null;

    /** The major version a database server is installed in (the confirmation's choice). */
    public ?string $installVersion = null;

    public string $defaultStack = Site::NGINX;

    public bool $configuring = false;

    public string $modsecMode = 'detect';

    public int $modsecParanoia = 1;

    public ?string $agentError = null;

    /** Why the last action could not be done. */
    public ?string $refused = null;

    public function mount(Components $components): void
    {
        if (! array_key_exists($this->tab, Components::TABS)) {
            $this->tab = 'web';
        }
        $this->defaultStack = $components->defaultStack();
        // Back on the page while something installs: show its progress.
        $this->operationId = ComponentOperation::running()->latest('id')->value('id');
    }

    /**
     * Opens the confirmation for install, update or remove.
     */
    public function ask(string $id, string $action, Components $components): void
    {
        if ((! $components->operable($id) && $id !== 'roundcube') || ! in_array($action, ['install', 'update', 'remove'], true)) {
            return;
        }
        $this->confirming = ['id' => $id, 'action' => $action];
        $choices = $action === 'install' ? ($components->candidate($id)['choices'] ?? []) : [];
        $this->installVersion = collect($choices)->firstWhere('default', true)['major'] ?? ($choices[0]['major'] ?? null);
    }

    public function confirm(Components $components, MailSettings $mail): void
    {
        $ask = $this->confirming;
        $this->confirming = null;
        $this->refused = null;
        if ($ask === null) {
            return;
        }
        if ($ask['id'] === 'roundcube') {
            $this->webmail($ask['action'] === 'install', $mail);

            return;
        }
        $result = $components->start($ask['id'], $ask['action'], auth()->user(), $ask['action'] === 'install' ? $this->installVersion : null);
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->operationId = $result->id;
    }

    /**
     * Refreshes the package lists to show which updates are available.
     */
    public function check(AgentClient $agent): void
    {
        $this->refused = null;
        try {
            $agent->call('component.check', [], 300);
        } catch (AgentException $e) {
            $this->refused = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Güncellemeler denetlenemedi: :reason', ['reason' => $e->reason]);

            return;
        }
        $this->dispatch('toast', message: __('Paket listeleri yenilendi.'));
    }

    public function saveDefault(Components $components): void
    {
        $this->validate(['defaultStack' => ['required', Rule::in(array_keys(Site::STACKS))]]);
        if ($this->defaultStack === Site::APACHE && ! $components->isInstalled('apache')) {
            $this->addError('defaultStack', __('Önce Apache\'yi kurun.'));

            return;
        }
        $previous = $components->defaultStack();
        Setting::put('web.default_stack', $this->defaultStack);
        $this->dispatch('toast',
            message: __('Yeni siteler :stacks ile açılacak.', ['stacks' => Site::STACKS[$this->defaultStack]]),
            source: $this->getId(),
            undo: ['method' => 'undoDefault', 'args' => [$previous]],
        );
    }

    public function undoDefault(string $previous): void
    {
        if (array_key_exists($previous, Site::STACKS)) {
            Setting::put('web.default_stack', $previous);
            $this->defaultStack = $previous;
        }
    }

    public function openSettings(Components $components): void
    {
        $settings = $components->statuses()['modsecurity']['settings'] ?? [];
        $this->modsecMode = $settings['mode'] ?? 'detect';
        $this->modsecParanoia = (int) ($settings['paranoia'] ?? 1);
        $this->resetValidation();
        $this->configuring = true;
    }

    public function saveSettings(AgentClient $agent, Components $components): void
    {
        $this->validate([
            'modsecMode' => ['required', Rule::in(['detect', 'block'])],
            'modsecParanoia' => ['required', 'integer', 'between:1,4'],
        ]);
        $previous = $components->statuses()['modsecurity']['settings'] ?? ['mode' => 'detect', 'paranoia' => 1];
        if (! $this->configureModsec($agent, $this->modsecMode, $this->modsecParanoia)) {
            return;
        }
        $this->configuring = false;
        $this->dispatch('toast',
            message: $this->modsecMode === 'block' ? __('ModSecurity saldırıları engelleyecek.') : __('ModSecurity yalnızca kaydedecek.'),
            source: $this->getId(),
            undo: ['method' => 'undoSettings', 'args' => [$previous['mode'] ?? 'detect', (int) ($previous['paranoia'] ?? 1)]],
        );
    }

    public function undoSettings(string $mode, int $paranoia, AgentClient $agent): void
    {
        if (in_array($mode, ['detect', 'block'], true) && $paranoia >= 1 && $paranoia <= 4) {
            $this->configureModsec($agent, $mode, $paranoia);
        }
    }

    public function render(Components $components, DatabaseServers $databases): View
    {
        $error = null;
        $groups = $components->rows($this->tab, $error);
        $this->agentError = $groups === null ? ($error ? __('Sunucu ajanı hata verdi: :error', ['error' => $error]) : __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')) : null;
        $operation = $this->operationId ? ComponentOperation::find($this->operationId) : null;
        $blocker = $components->blocker();
        $manager = null;
        if ($this->tab === 'veritabani' && $groups !== null) {
            $manager = $databases->status()['manager'] ?? null;
        }

        return view('livewire.components.index', [
            'groups' => $groups ?? [],
            'operation' => $operation,
            'operationName' => $operation ? $components->operationName($operation) : null,
            'blocker' => $blocker,
            'busy' => $blocker !== null || collect($groups ?? [])->flatten(1)->contains(fn ($r) => $r['busy'] !== null),
            'checkedAt' => $components->checkedAt(),
            'apacheInstalled' => $components->isInstalled('apache'),
            'manager' => $manager,
            'managerPort' => $databases->managerPort(),
            'confirm' => $this->confirming ? $this->confirmText($components, $this->confirming['id'], $this->confirming['action']) : null,
        ]);
    }

    private function configureModsec(AgentClient $agent, string $mode, int $paranoia): bool
    {
        try {
            $agent->call('component.configure', ['id' => 'modsecurity', 'settings' => ['modsecurity_mode' => $mode, 'modsecurity_paranoia' => $paranoia]], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->addError('modsecMode', $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Ayarlar uygulanamadı, önceki ayarlar korundu: :reason', ['reason' => $e->reason]));

            return false;
        }

        return true;
    }

    /**
     * Turns the webmail on or off; the mail server is set up again.
     */
    private function webmail(bool $on, MailSettings $mail): void
    {
        if (! $mail->isSetUp() || ($mail->server()['state'] ?? null) === 'installing') {
            return;
        }
        $mail->save(['webmail' => $on]);
        $mail->saveServer(['state' => 'installing', 'error' => null]);
        SetupMailServer::dispatch();
        $this->dispatch('toast', message: $on ? __('Roundcube açılıyor.') : __('Roundcube kapatılıyor.'));
    }

    /**
     * Title, text and button of the confirmation.
     *
     * @return array{title: string, text: string, button: string, danger: bool, candidate?: array{version: ?string, source: ?string}|null}
     */
    private function confirmText(Components $components, string $id, string $action): array
    {
        $name = $components->name($id);
        $row = collect($components->rows($components->meta($id)[2]) ?? [])->flatten(1)->firstWhere('id', $id) ?? [];
        $version = $row['latest'] ?? $row['version'] ?? null;

        return match ($action) {
            'install' => [
                'title' => __(':name kurulsun mu?', ['name' => $name]),
                'text' => match (true) {
                    $id === 'roundcube' => __('Posta sunucusu yeniden ayarlanır ve webmail adresi açılır.'),
                    $id === 'clamav' => __('Serfex Agent paketleri Ubuntu depolarından kurar (yaklaşık 1 GB bellek kullanır), virüs imzalarını indirir ve posta taramasına bağlar. Siteler bu sırada çalışmaya devam eder.'),
                    str_starts_with($id, 'node') => __('Serfex Agent Node.js\'i nodejs.org\'dan indirir ve imzasını denetler. Siteler bu sırada çalışmaya devam eder.'),
                    $id === 'composer' => __('Serfex Agent Composer\'ı getcomposer.org\'dan indirir ve imzasını denetler.'),
                    default => __('Serfex Agent paketleri kurar, ayarlarını yazar ve hizmeti başlatıp test eder. Bir adım başarısız olursa yapılanlar geri alınır. Siteler bu sırada çalışmaya devam eder.'),
                },
                'button' => __('Kur'),
                'danger' => false,
                'candidate' => $components->candidate($id),
            ],
            'update' => [
                'title' => __(':name güncellensin mi?', ['name' => $name]),
                'text' => __('Yeni sürüm:version kurulur ve hizmet yeniden başlatılır; birkaç saniyelik kesinti olabilir. Yeni sürüm çalışmazsa ayarlar geri alınır.', ['version' => ($version ? " ({$version})" : '')]),
                'button' => __('Güncelle'),
                'danger' => false,
            ],
            default => [
                'title' => __(':name kaldırılsın mı?', ['name' => $name]),
                'text' => match (true) {
                    $id === 'roundcube' => __('Kullanıcılar postalarına tarayıcıdan ulaşamaz; posta programları çalışmaya devam eder.'),
                    $id === 'modsecurity' => __('Siteler güvenlik duvarı olmadan çalışır.'),
                    $id === 'fail2ban' => __('Kaba kuvvet saldırıları otomatik engellenmez. Canlı İzleme engellemeleri çalışmaya devam eder.'),
                    $id === 'clamav' => __('Posta virüs taraması kapanır.'),
                    in_array($id, ['mysql', 'mariadb'], true) => __('Paketler kaldırılır; veri klasörü silinmez, yedek klasörüne taşınır.'),
                    $id === 'postgresql' => __('Müşteri kümesi durdurulup silinir. Panelin kendi PostgreSQL\'i etkilenmez.'),
                    default => __('Bu bileşeni kullanan bir şey yok.'),
                }.' '.__('Kaldırmadan önce yapılandırması /var/lib/serfex/backups/components altına yedeklenir.'),
                'button' => __('Kaldır'),
                'danger' => true,
            ],
        };
    }
}
