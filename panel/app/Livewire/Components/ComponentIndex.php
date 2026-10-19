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
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
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
#[Title('Yazılım Yönetimi · Servik')]
class ComponentIndex extends Component
{
    #[Url(as: 'sekme', except: 'web')]
    public string $tab = 'web';

    /** @var array{id: string, action: string}|null asked to confirm */
    public ?array $confirming = null;

    /** The operation whose progress is shown. */
    public ?int $operationId = null;

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
        $result = $components->start($ask['id'], $ask['action'], auth()->user());
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->operationId = $result->id;
    }

    /**
     * Shows the log of the operation running on a row.
     */
    public function show(int $operationId): void
    {
        if (ComponentOperation::whereKey($operationId)->exists()) {
            $this->operationId = $operationId;
        }
    }

    public function closeOperation(): void
    {
        $this->operationId = null;
    }

    /**
     * The run log of the operation shown, from $offset on.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, status: string, error?: ?string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $op = ComponentOperation::where('run', $run)->first();
        if ($op === null) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('component.run.read', ['run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => ! $op->isRunning(), 'status' => $op->status, 'error' => 'Sunucu ajanına ulaşılamıyor.'];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $op->isRunning(),
            'status' => $op->status,
            'error' => $op->status === 'failed' ? $op->error : null,
        ];
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
            $this->refused = $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamıyor.' : 'Güncellemeler denetlenemedi: '.$e->reason;

            return;
        }
        $this->dispatch('toast', message: 'Paket listeleri yenilendi.');
    }

    public function saveDefault(Components $components): void
    {
        $this->validate(['defaultStack' => ['required', Rule::in(array_keys(Site::STACKS))]]);
        if ($this->defaultStack === Site::APACHE && ! $components->isInstalled('apache')) {
            $this->addError('defaultStack', 'Önce Apache\'yi kurun.');

            return;
        }
        $previous = $components->defaultStack();
        Setting::put('web.default_stack', $this->defaultStack);
        $this->dispatch('toast',
            message: 'Yeni siteler '.Site::STACKS[$this->defaultStack].' ile açılacak.',
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
            message: $this->modsecMode === 'block' ? 'ModSecurity saldırıları engelleyecek.' : 'ModSecurity yalnızca kaydedecek.',
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
        $this->agentError = $groups === null ? ($error ? 'Sunucu ajanı hata verdi: '.$error : 'Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status servik-agent" ile kontrol edin.') : null;
        $operation = $this->operationId ? ComponentOperation::find($this->operationId) : null;
        $busy = ComponentOperation::running()->exists();
        $manager = null;
        if ($this->tab === 'veritabani' && $groups !== null) {
            $manager = $databases->status()['manager'] ?? null;
        }

        return view('livewire.components.index', [
            'groups' => $groups ?? [],
            'operation' => $operation,
            'operationName' => $operation ? $components->name($operation->component) : null,
            'busy' => $busy || collect($groups ?? [])->flatten(1)->contains(fn ($r) => $r['busy'] !== null),
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
            $agent->call('component.configure', ['id' => 'modsecurity', 'settings' => ['modsecurity_mode' => $mode, 'modsecurity_paranoia' => $paranoia]], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->addError('modsecMode', $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamıyor.' : 'Ayarlar uygulanamadı, önceki ayarlar korundu: '.$e->reason);

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
        $this->dispatch('toast', message: $on ? 'Roundcube açılıyor.' : 'Roundcube kapatılıyor.');
    }

    /**
     * Title, text and button of the confirmation.
     *
     * @return array{title: string, text: string, button: string, danger: bool}
     */
    private function confirmText(Components $components, string $id, string $action): array
    {
        $name = $components->name($id);
        $row = collect($components->rows($components->meta($id)[2]) ?? [])->flatten(1)->firstWhere('id', $id) ?? [];
        $version = $row['latest'] ?? $row['version'] ?? null;

        return match ($action) {
            'install' => [
                'title' => "{$name} kurulsun mu?",
                'text' => match (true) {
                    $id === 'roundcube' => 'Posta sunucusu yeniden ayarlanır ve webmail adresi açılır.',
                    $id === 'clamav' => 'Servik Agent paketleri Ubuntu depolarından kurar (yaklaşık 1 GB bellek kullanır), virüs imzalarını indirir ve posta taramasına bağlar. Siteler bu sırada çalışmaya devam eder.',
                    str_starts_with($id, 'node') => 'Servik Agent Node.js\'i nodejs.org\'dan indirir ve imzasını denetler. Siteler bu sırada çalışmaya devam eder.',
                    $id === 'composer' => 'Servik Agent Composer\'ı getcomposer.org\'dan indirir ve imzasını denetler.',
                    default => 'Servik Agent paketleri Ubuntu depolarından kurar, ayarlarını yazar ve hizmeti başlatıp test eder. Bir adım başarısız olursa yapılanlar geri alınır. Siteler bu sırada çalışmaya devam eder.',
                },
                'button' => 'Kur',
                'danger' => false,
            ],
            'update' => [
                'title' => "{$name} güncellensin mi?",
                'text' => 'Yeni sürüm'.($version ? " ({$version})" : '').' kurulur ve hizmet yeniden başlatılır; birkaç saniyelik kesinti olabilir. Yeni sürüm çalışmazsa ayarlar geri alınır.',
                'button' => 'Güncelle',
                'danger' => false,
            ],
            default => [
                'title' => "{$name} kaldırılsın mı?",
                'text' => match (true) {
                    $id === 'roundcube' => 'Kullanıcılar postalarına tarayıcıdan ulaşamaz; posta programları çalışmaya devam eder.',
                    $id === 'modsecurity' => 'Siteler güvenlik duvarı olmadan çalışır.',
                    $id === 'fail2ban' => 'Kaba kuvvet saldırıları otomatik engellenmez. Canlı İzleme engellemeleri çalışmaya devam eder.',
                    $id === 'clamav' => 'Posta virüs taraması kapanır.',
                    in_array($id, ['mysql', 'mariadb'], true) => 'Paketler kaldırılır; veri klasörü silinmez, yedek klasörüne taşınır.',
                    $id === 'postgresql' => 'Müşteri kümesi durdurulup silinir. Panelin kendi PostgreSQL\'i etkilenmez.',
                    default => 'Bu bileşeni kullanan bir şey yok.',
                }.' Kaldırmadan önce yapılandırması /var/lib/servik/backups/components altına yedeklenir.',
                'button' => 'Kaldır',
                'danger' => true,
            ],
        };
    }
}
