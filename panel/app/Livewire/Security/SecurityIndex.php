<?php

namespace App\Livewire\Security;

use App\Models\IpBlock;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use App\Services\Monitor\MonitorException;
use App\Services\Security\Fail2ban;
use App\Services\Security\Firewall;
use App\Services\Security\Scans;
use App\Services\Security\Ssh;
use App\Services\Security\TwoFactor;
use App\Services\Security\Waf;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Güvenlik: the server's firewall (ports), the web application firewall
 * and what it caught, the security scan's findings, and how people log in
 * (the panel, two-step login, SSH). Administrators only; customers see
 * their own sites' WAF and scan on the site pages.
 */
#[Layout('components.layouts.app')]
#[Title('Güvenlik · Servik')]
class SecurityIndex extends Component
{
    use BlocksAddresses, HandlesFindings, WithPagination;

    public const TABS = [
        'guvenlik-duvari' => 'Güvenlik duvarı',
        'waf' => 'WAF',
        'tarama' => 'Tarama',
        'giris' => 'Giriş güvenliği',
    ];

    #[Url(as: 'sekme', except: 'guvenlik-duvari')]
    public string $tab = 'guvenlik-duvari';

    /** Why the last action could not be done. */
    public ?string $refused = null;

    // --- Güvenlik duvarı ---

    public bool $confirmingFirewall = false;

    public bool $editingRule = false;

    public ?string $ruleId = null;

    /** @var array{name: string, ports: string, protocol: string, sources: string, action: string} */
    public array $rule = ['name' => '', 'ports' => '', 'protocol' => 'tcp', 'sources' => '', 'action' => 'allow'];

    // --- WAF ---

    #[Url(as: 'site', except: 0)]
    public int $wafSite = 0;

    public bool $configuringWaf = false;

    public string $wafMode = 'detect';

    public int $wafParanoia = 1;

    // --- Tarama ---

    #[Url(as: 'durum', except: 'open')]
    public string $findingStatus = 'open';

    // --- Giriş güvenliği ---

    public string $require2fa = 'none';

    public string $rootLogin = 'prohibit-password';

    public bool $passwordAuth = true;

    public ?int $resettingUser = null;

    public bool $resetting = false;

    /** The SSH form holds the server's values once read. */
    public bool $sshLoaded = false;

    public function mount(TwoFactor $twoFactor): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'guvenlik-duvari';
        }
        $this->require2fa = $twoFactor->required();
    }

    public function updatedTab(): void
    {
        $this->refused = null;
        $this->resetPage();
    }

    public function updatedFindingStatus(): void
    {
        $this->resetPage();
    }

    protected function findingScope(): Builder
    {
        return SecurityFinding::query();
    }

    // --- Güvenlik duvarı ---------------------------------------------

    public function setFirewall(bool $on, Firewall $firewall): void
    {
        $settings = $firewall->settings();
        $previous = $settings;
        $settings['enabled'] = $on;
        if ($this->saveFirewall($firewall, $settings, $previous, $on ? 'Güvenlik duvarı açıldı; yalnızca izin verilen portlar yanıt veriyor.' : 'Güvenlik duvarı kapatıldı; bütün portlar açık.')) {
            $this->confirmingFirewall = false;
        }
    }

    public function toggleService(string $id, Firewall $firewall): void
    {
        $services = $firewall->services();
        if (! isset($services[$id]) || $services[$id]['locked']) {
            return;
        }
        $settings = $firewall->settings();
        $previous = $settings;
        $settings['services'][$id] = ! $services[$id]['enabled'];
        $this->saveFirewall($firewall, $settings, $previous, $services[$id]['name'].($settings['services'][$id] ? ' için portlar açıldı.' : ' için portlar kapatıldı.'));
    }

    public function openRule(?string $id, Firewall $firewall): void
    {
        $existing = $id ? collect($firewall->settings()['rules'])->firstWhere('id', $id) : null;
        $this->ruleId = $existing['id'] ?? null;
        $this->rule = $existing
            ? ['name' => $existing['name'], 'ports' => $existing['ports'], 'protocol' => $existing['protocol'], 'sources' => implode("\n", $existing['sources'] ?? []), 'action' => $existing['action']]
            : ['name' => '', 'ports' => '', 'protocol' => 'tcp', 'sources' => '', 'action' => 'allow'];
        $this->resetValidation();
        $this->editingRule = true;
    }

    public function saveRule(Firewall $firewall): void
    {
        $sources = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->rule['sources']))));
        $rule = [
            'name' => trim((string) $this->rule['name']),
            'ports' => trim((string) $this->rule['ports']),
            'protocol' => (string) $this->rule['protocol'],
            'sources' => $sources,
            'action' => $this->rule['action'] === 'deny' ? 'deny' : 'allow',
        ];
        $this->resetValidation();
        $problems = $firewall->problems($rule);
        $settings = $firewall->settings();
        if ($this->ruleId === null && count($settings['rules']) >= Firewall::MAX_RULES) {
            $problems['name'] = 'En fazla '.Firewall::MAX_RULES.' kural eklenebilir.';
        }
        if ($problems !== []) {
            foreach ($problems as $field => $message) {
                $this->addError('rule.'.$field, $message);
            }

            return;
        }
        $previous = $settings;
        $rule['id'] = $this->ruleId ?? Firewall::newId();
        $rule['enabled'] = true;
        $index = collect($settings['rules'])->search(fn ($r) => $r['id'] === $rule['id']);
        $index === false ? $settings['rules'][] = $rule : $settings['rules'][$index] = $rule;
        if ($this->saveFirewall($firewall, $settings, $previous, $rule['name'].' kaydedildi.', 'rule.name')) {
            $this->editingRule = false;
        }
    }

    public function deleteRule(string $id, Firewall $firewall): void
    {
        $settings = $firewall->settings();
        $previous = $settings;
        $rule = collect($settings['rules'])->firstWhere('id', $id);
        if ($rule === null) {
            return;
        }
        $settings['rules'] = array_values(array_filter($settings['rules'], fn ($r) => $r['id'] !== $id));
        $this->saveFirewall($firewall, $settings, $previous, $rule['name'].' silindi.');
    }

    /**
     * Undo of every firewall change: the settings as they were.
     *
     * @param  array<string, mixed>  $settings
     */
    public function restoreFirewall(array $settings, Firewall $firewall): void
    {
        $settings = [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'services' => array_map('boolval', (array) ($settings['services'] ?? [])),
            'rules' => array_values(array_filter((array) ($settings['rules'] ?? []), fn ($r) => is_array($r) && isset($r['id'], $r['name'], $r['ports'], $r['protocol'], $r['action'])
                && preg_match('/^r-[a-z0-9]{10}$/', (string) $r['id']) && $firewall->problems(['sources' => [], 'action' => 'allow'] + $r) === [])),
        ];
        $this->saveFirewall($firewall, $settings, null, 'Güvenlik duvarı önceki haline döndü.');
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $previous  offered for Undo
     */
    private function saveFirewall(Firewall $firewall, array $settings, ?array $previous, string $message, ?string $errorField = null): bool
    {
        try {
            $firewall->save($settings, request()->ip());
        } catch (MonitorException $e) {
            $errorField ? $this->addError($errorField, $e->getMessage()) : $this->refused = $e->getMessage();

            return false;
        } catch (AgentException $e) {
            $errorField ? $this->addError($errorField, Firewall::message($e)) : $this->refused = Firewall::message($e);

            return false;
        }
        $this->refused = null;
        $this->dispatch('toast', message: $message, source: $this->getId(),
            undo: $previous !== null ? ['method' => 'restoreFirewall', 'args' => [$previous]] : null);

        return true;
    }

    // --- WAF ---------------------------------------------------------

    public function openWafSettings(Waf $waf): void
    {
        $server = $waf->server();
        $this->wafMode = $server['mode'];
        $this->wafParanoia = $server['paranoia'];
        $this->resetValidation();
        $this->configuringWaf = true;
    }

    public function saveWafSettings(Waf $waf): void
    {
        $this->validate([
            'wafMode' => ['required', Rule::in(['detect', 'block'])],
            'wafParanoia' => ['required', 'integer', 'between:1,4'],
        ]);
        $previous = $waf->server();
        try {
            $waf->configureServer($this->wafMode, $this->wafParanoia);
        } catch (AgentException $e) {
            $this->addError('wafMode', Waf::message($e));

            return;
        }
        $this->configuringWaf = false;
        $this->dispatch('toast',
            message: $this->wafMode === 'block' ? 'WAF artık saldırıları engelliyor.' : 'WAF saldırıları yalnızca kaydediyor.',
            source: $this->getId(),
            undo: ['method' => 'undoWafSettings', 'args' => [$previous['mode'], $previous['paranoia']]],
        );
    }

    public function undoWafSettings(string $mode, int $paranoia, Waf $waf): void
    {
        if (in_array($mode, ['detect', 'block'], true) && $paranoia >= 1 && $paranoia <= 4) {
            try {
                $waf->configureServer($mode, $paranoia);
            } catch (AgentException $e) {
                $this->refused = Waf::message($e);
            }
        }
    }

    // --- Tarama ------------------------------------------------------

    public function scanServer(Scans $scans): void
    {
        $scan = $scans->start(null, 'manual', auth()->user());
        if (is_string($scan)) {
            $this->dispatch('toast', message: $scan);

            return;
        }
        $this->dispatch('toast', message: 'Tarama başladı; bütün sitelerin dosyaları okunuyor.');
    }

    // --- Giriş güvenliği ---------------------------------------------

    public function saveTwoFactor(TwoFactor $twoFactor): void
    {
        $this->validate(['require2fa' => ['required', Rule::in(array_keys(TwoFactor::REQUIRE))]]);
        $previous = $twoFactor->required();
        if ($previous === $this->require2fa) {
            return;
        }
        $twoFactor->setRequired($this->require2fa);
        $this->dispatch('toast',
            message: $this->require2fa === 'none' ? 'İki adımlı doğrulama artık isteğe bağlı.' : 'Kurmamış hesaplar ilk girişte iki adımlı doğrulamayı kuracak.',
            source: $this->getId(),
            undo: ['method' => 'undoTwoFactor', 'args' => [$previous]],
        );
    }

    public function undoTwoFactor(string $previous, TwoFactor $twoFactor): void
    {
        if (array_key_exists($previous, TwoFactor::REQUIRE)) {
            $twoFactor->setRequired($previous);
            $this->require2fa = $previous;
        }
    }

    public function confirmReset(int $id): void
    {
        $this->resettingUser = User::whereKey($id)->whereKeyNot(auth()->id())->whereNotNull('two_factor_confirmed_at')->value('id');
        $this->resetting = $this->resettingUser !== null;
    }

    public function resetTwoFactor(TwoFactor $twoFactor): void
    {
        $user = $this->resetting && $this->resettingUser ? User::find($this->resettingUser) : null;
        $this->resettingUser = null;
        $this->resetting = false;
        if ($user === null) {
            return;
        }
        $twoFactor->disable($user);
        $this->dispatch('toast', message: $user->name.' için iki adımlı doğrulama kapatıldı; bir sonraki girişte yeniden kurabilir.');
    }

    public function saveSsh(Ssh $ssh): void
    {
        $this->validate(['rootLogin' => ['required', Rule::in(array_keys(Ssh::ROOT_LOGIN))], 'passwordAuth' => ['boolean']]);
        try {
            $before = $ssh->status();
            $ssh->apply($this->rootLogin, $this->passwordAuth);
        } catch (AgentException $e) {
            $this->addError('rootLogin', Ssh::message($e));

            return;
        }
        $this->sshLoaded = true;
        $this->dispatch('toast', message: 'SSH ayarları uygulandı.', source: $this->getId(),
            undo: ['method' => 'undoSsh', 'args' => [(string) ($before['root_login'] ?? 'prohibit-password'), (bool) ($before['password_auth'] ?? true)]]);
    }

    public function undoSsh(string $rootLogin, bool $passwordAuth, Ssh $ssh): void
    {
        if (! array_key_exists($rootLogin, Ssh::ROOT_LOGIN)) {
            return;
        }
        try {
            $ssh->apply($rootLogin, $passwordAuth);
        } catch (AgentException $e) {
            $this->refused = Ssh::message($e);

            return;
        }
        $this->rootLogin = $rootLogin;
        $this->passwordAuth = $passwordAuth;
    }

    // --- Render ------------------------------------------------------

    public function render(Components $components): View
    {
        $data = ['tab' => $this->tab];
        $error = null;

        match ($this->tab) {
            'waf' => $data += $this->wafData($components),
            'tarama' => $data += $this->scanData(),
            'giris' => $data += $this->loginData($components),
            default => $data += $this->firewallData($error),
        };

        return view('livewire.security.index', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function firewallData(?string &$error): array
    {
        $firewall = app(Firewall::class);
        $status = null;
        try {
            $status = $firewall->status();
        } catch (AgentException $e) {
            $error = Firewall::message($e);
        }
        $settings = $firewall->settings();
        $services = $firewall->services();
        // Ports something listens on that no rule opens: closed while
        // the firewall is on.
        $open = [];
        foreach ([...array_filter($services, fn ($s) => $s['enabled']), ...array_filter($settings['rules'], fn ($r) => $r['action'] === 'allow')] as $r) {
            foreach (explode(',', $r['ports']) as $part) {
                [$lo, $hi] = array_pad(array_map('intval', explode('-', trim($part))), 2, null);
                $open[] = [$lo, $hi ?? $lo, $r['protocol']];
            }
        }
        $uncovered = collect($status['listening'] ?? [])
            ->reject(fn ($l) => in_array($l['address'], ['127.0.0.1', '::1'], true) || str_starts_with((string) $l['address'], '127.'))
            ->reject(fn ($l) => collect($open)->contains(fn ($o) => $l['port'] >= $o[0] && $l['port'] <= $o[1] && ($o[2] === 'both' || $o[2] === $l['protocol'])))
            ->unique(fn ($l) => $l['protocol'].$l['port'])->sortBy('port')->values();

        return [
            'firewall' => $settings,
            'services' => $services,
            'active' => (bool) ($status['active'] ?? false),
            'agentError' => $error,
            'uncovered' => $uncovered,
            'myIp' => request()->ip(),
            'panelPort' => $services['panel']['ports'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function wafData(Components $components): array
    {
        $waf = app(Waf::class);
        $installed = $waf->installed();
        $events = [];
        $error = null;
        $sites = Site::orderBy('domain')->get(['id', 'domain', 'aliases', 'waf_mode']);
        if ($installed) {
            try {
                $filter = $this->wafSite ? $sites->where('id', $this->wafSite)->values() : null;
                $events = $waf->events($filter, limit: 100)['events'];
            } catch (AgentException $e) {
                $error = Waf::message($e);
            }
        }
        $server = $installed ? $waf->server() : ['mode' => 'detect', 'paranoia' => 1];

        return [
            'installed' => $installed,
            'server' => $server,
            'events' => $events,
            'wafError' => $error,
            'sites' => $sites,
            'ownModes' => $sites->whereNotNull('waf_mode')->count(),
            'blocked' => IpBlock::active()->pluck('address')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scanData(): array
    {
        $running = SecurityScan::running()->where('scope', 'server')->latest('id')->first();
        $last = SecurityScan::where('scope', 'server')->whereNotIn('status', ['queued', 'running'])->latest('id')->first();
        $status = in_array($this->findingStatus, ['open', 'fixed', 'ignored'], true) ? $this->findingStatus : 'open';

        return [
            'running' => $running,
            'last' => $last,
            'findings' => SecurityFinding::with('site')->where('status', $status)->worstFirst()->paginate(50),
            'counts' => SecurityFinding::open()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->all(),
            'statusCounts' => SecurityFinding::whereIn('status', ['open', 'fixed', 'ignored'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loginData(Components $components): array
    {
        $fail2ban = null;
        $ssh = null;
        $sshError = null;
        try {
            $ssh = app(Ssh::class)->status();
            if (! $this->sshLoaded) {
                $this->rootLogin = (string) ($ssh['root_login'] ?? 'prohibit-password');
                $this->passwordAuth = (bool) ($ssh['password_auth'] ?? true);
                $this->sshLoaded = true;
            }
        } catch (AgentException $e) {
            $sshError = Ssh::message($e);
        }
        if ($components->isInstalled('fail2ban')) {
            try {
                $fail2ban = app(Fail2ban::class)->status();
            } catch (AgentException) {
                $fail2ban = null;
            }
        }

        return [
            'ssh' => $ssh,
            'sshError' => $sshError,
            'fail2banInstalled' => $components->isInstalled('fail2ban'),
            'fail2ban' => $fail2ban,
            'bans' => IpBlock::active()->where('source', 'fail2ban')->count(),
            'users' => User::orderByDesc('is_admin')->orderBy('name')->get(['id', 'name', 'email', 'is_admin', 'customer_id', 'two_factor_confirmed_at']),
        ];
    }
}
