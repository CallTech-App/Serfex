<?php

namespace App\Livewire\Components;

use App\Jobs\SetupMailServer;
use App\Models\ComponentOperation;
use App\Models\Database;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Components\SystemPackages;
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
 * removed and the page says what uses it. A database server can be
 * upgraded to the next major version Serfex allows; the old version is
 * kept for a week and removed from here.
 *
 * Ubuntu güncellemeleri lists Ubuntu's own packages with updates: full
 * administrators upgrade all or a chosen few, turn the automatic security
 * updates on or off and restart the server when an upgrade asks for it.
 */
#[Layout('components.layouts.app')]
#[Title('Yazılım Yönetimi · Serfex')]
class ComponentIndex extends Component
{
    use ShowsComponentOperations;

    #[Url(except: 'web')]
    public string $tab = 'web';

    /** @var array{id: string, action: string, to?: ?string}|null asked to confirm */
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

    /** @var list<string> Ubuntu packages ticked on the Ubuntu güncellemeleri tab */
    public array $selected = [];

    /** all or security: which Ubuntu updates the table shows. */
    public string $packageFilter = 'all';

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
     * Opens the confirmation for install, update, remove, upgrade or
     * remove-old.
     */
    public function ask(string $id, string $action, Components $components): void
    {
        if ((! $components->operable($id) && ! array_key_exists($id, MailSettings::WEBMAIL_APPS)) || ! in_array($action, ['install', 'update', 'remove', 'upgrade', 'remove-old'], true)) {
            return;
        }
        $this->confirming = ['id' => $id, 'action' => $action];
        if ($action === 'upgrade') {
            // The version confirmed is the one upgraded to, even when a
            // newer offer turns up meanwhile.
            $this->confirming['to'] = $components->statuses()[$id]['upgrade']['to'] ?? null;
        }
        $choices = $action === 'install' ? ($components->candidate($id)['choices'] ?? []) : [];
        $this->installVersion = collect($choices)->firstWhere('default', true)['major'] ?? ($choices[0]['major'] ?? null);
    }

    /**
     * Opens the confirmation for upgrading Ubuntu packages: every update
     * (all), the ticked ones (selected) or one ($name).
     */
    public function askPackages(string $scope, ?string $name = null): void
    {
        $packages = match ($scope) {
            'one' => $name !== null ? [$name] : [],
            'selected' => array_values(array_filter($this->selected, 'is_string')),
            default => null,
        };
        if ($packages === []) {
            return;
        }
        $this->confirming = ['id' => SystemPackages::COMPONENT, 'action' => SystemPackages::ACTION, 'packages' => $packages];
    }

    public function askReboot(): void
    {
        $this->confirming = ['id' => SystemPackages::COMPONENT, 'action' => 'reboot'];
    }

    public function confirm(Components $components, MailSettings $mail, SystemPackages $packages, AgentClient $agent): void
    {
        $ask = $this->confirming;
        $this->confirming = null;
        $this->refused = null;
        if ($ask === null) {
            return;
        }
        if ($ask['id'] === SystemPackages::COMPONENT) {
            $ask['action'] === 'reboot' ? $this->reboot($agent, $packages) : $this->upgradePackages($ask['packages'] ?? null, $packages);

            return;
        }
        if (array_key_exists($ask['id'], MailSettings::WEBMAIL_APPS)) {
            $this->webmail($ask['id'], $ask['action'], $mail);

            return;
        }
        $version = match ($ask['action']) {
            'install' => $this->installVersion,
            'upgrade' => $ask['to'] ?? null,
            default => null,
        };
        $result = $components->start($ask['id'], $ask['action'], auth()->user(), $version);
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->operationId = $result->id;
    }

    /**
     * Refreshes the package lists to show which updates are available.
     */
    public function check(AgentClient $agent, MailSettings $mail, SystemPackages $packages): void
    {
        $this->refused = null;
        try {
            $agent->call('component.check', [], 300);
            $packages->forget();
            $mail->checkWebmailUpdate($agent);
        } catch (AgentException $e) {
            $this->refused = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Güncellemeler denetlenemedi: :reason', ['reason' => $e->reason]);

            return;
        }
        $this->dispatch('toast', message: __('Paket listeleri yenilendi.'));
    }

    /**
     * Ticks every Ubuntu update that can be chosen alone, or none.
     */
    public function selectAllPackages(bool $all, SystemPackages $packages): void
    {
        $this->selected = $all ? collect($packages->rows())->where('selectable', true)->when($this->packageFilter === 'security', fn ($c) => $c->where('security', true))->pluck('name')->all() : [];
    }

    /**
     * Turns Ubuntu's automatic security updates on or off (Undo turns them
     * back).
     */
    public function setAutoUpdates(bool $on, AgentClient $agent, SystemPackages $packages): void
    {
        $this->refused = null;
        if (! auth()->user()?->isFullAdmin()) {
            $this->refused = __('Otomatik güncellemeleri yalnızca tam yetkili yöneticiler değiştirebilir.');

            return;
        }
        try {
            $agent->call('packages.auto', ['enabled' => $on], 300);
        } catch (AgentException $e) {
            $this->refused = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Otomatik güncellemeler değiştirilemedi: :reason', ['reason' => $e->reason]);

            return;
        }
        $packages->forget();
        $this->dispatch('toast',
            message: $on ? __('Güvenlik güncellemeleri her gün kendiliğinden kurulacak.') : __('Güvenlik güncellemeleri artık kendiliğinden kurulmayacak.'),
            source: $this->getId(),
            undo: ['method' => 'setAutoUpdates', 'args' => [! $on]],
        );
    }

    /**
     * Stops a restart started from this page (the toast's Undo).
     */
    public function abortReboot(AgentClient $agent, SystemPackages $packages): void
    {
        $this->refused = null;
        if (! auth()->user()?->isFullAdmin()) {
            return;
        }
        try {
            $agent->call('system.reboot', ['cancel' => true], 30);
        } catch (AgentException $e) {
            $this->refused = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : __('Yeniden başlatma durdurulamadı: :reason', ['reason' => $e->reason]);

            return;
        }
        $packages->forget();
        $this->dispatch('toast', message: __('Sunucunun yeniden başlatılması iptal edildi.'));
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

    public function render(Components $components, DatabaseServers $databases, SystemPackages $packages): View
    {
        $error = null;
        $groups = $components->rows($this->tab, $error);
        $system = null;
        if ($this->tab === 'packages' && $groups !== null) {
            $packageError = null;
            $list = $packages->list($packageError);
            $rows = $list === null ? [] : $packages->rows();
            $this->selected = array_values(array_intersect($this->selected, array_column(array_filter($rows, fn ($r) => $r['selectable']), 'name')));
            $system = [
                'error' => $list === null ? ($packageError ? __('Paket listesi okunamadı: :error', ['error' => $packageError]) : __('Sunucu ajanına ulaşılamıyor.')) : null,
                'rows' => $this->packageFilter === 'security' ? array_values(array_filter($rows, fn ($r) => $r['security'])) : $rows,
                'counts' => [
                    'all' => count($rows),
                    'security' => count(array_filter($rows, fn ($r) => $r['security'])),
                    'upgradable' => count(array_filter($rows, fn ($r) => $r['selectable'] && ! $r['kept_back'])),
                    'new' => count(array_filter($rows, fn ($r) => $r['new'])),
                ],
                'reboot' => $list['reboot'] ?? ['required' => false],
                'auto' => $list['auto'] ?? null,
                'checked_at' => $list['checked_at'] ?? null,
                'can' => (bool) auth()->user()?->isFullAdmin(),
            ];
        }
        $this->agentError = $groups === null ? ($error ? __('Sunucu ajanı hata verdi: :error', ['error' => $error]) : __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')) : null;
        $operation = $this->operationId ? ComponentOperation::find($this->operationId) : null;
        $blocker = $components->blocker();
        $manager = null;
        if ($this->tab === 'databases' && $groups !== null) {
            $manager = $databases->status()['manager'] ?? null;
        }

        return view('livewire.components.index', [
            'groups' => $groups ?? [],
            'operation' => $operation,
            'operationName' => $operation ? $components->operationName($operation) : null,
            'blocker' => $blocker,
            'busy' => $blocker !== null || collect($groups ?? [])->flatten(1)->contains(fn ($r) => $r['busy'] !== null),
            'checkedAt' => $system['checked_at'] ?? $components->checkedAt(),
            'system' => $system,
            'apacheInstalled' => $components->isInstalled('apache'),
            'manager' => $manager,
            'managerPort' => $databases->managerPort(),
            'confirm' => $this->confirming ? $this->confirmText($components, $this->confirming['id'], $this->confirming['action']) : null,
        ]);
    }

    /**
     * The upgrade confirmation: what happens, how long customers wait and
     * what is kept.
     *
     * @param  array<string, mixed>  $row
     * @return array{title: string, text: string, button: string, danger: bool, upgrade: array<string, mixed>}
     */
    private function upgradeText(string $id, array $row): array
    {
        $upgrade = $row['upgrade'] ?? ['from' => '', 'to' => '', 'name' => ''];
        $server = $id === 'postgresql' ? DatabaseServers::POSTGRESQL : DatabaseServers::MYSQL;
        $databases = Database::where('server', $server)->count();

        return [
            'title' => __(':name sürümüne yükseltilsin mi?', ['name' => $upgrade['name']]),
            'text' => __('Serfex önce yeterli boş disk olduğunu denetler, sunucuyu bakım kipine alır ve her veritabanının tam dökümünü yedek klasörüne yazar. Ardından yeni sürümü kurar, verileri taşır ve her veritabanını, kullanıcıyı ve tablolardaki satır sayılarını yükseltmeden öncekiyle karşılaştırır. Bir adım başarısız olursa eski sürüme dönülür ve hiçbir veri kaybolmaz.'),
            'button' => __('Yükselt'),
            'danger' => false,
            'upgrade' => [
                'from' => Components::releaseName($id, $upgrade['from']),
                'to' => $upgrade['name'],
                'databases' => $databases,
                'downtime' => $id === 'postgresql'
                    ? __('Yükseltme boyunca siteler veritabanlarına bağlanamaz. Süre veri boyutuna bağlıdır: birkaç GB için genellikle birkaç dakika.')
                    : __('Döküm alınırken veritabanları salt okunurdur, yeni sürüm kurulurken bağlantı kesilir. Süre veri boyutuna bağlıdır: birkaç GB için genellikle birkaç dakika.'),
                'kept' => ($id === 'postgresql'
                    ? __('Eski küme durdurulmuş olarak 7 gün saklanır, sonra kendiliğinden silinir; daha önce bu sayfadan kaldırabilirsiniz.')
                    : __('Eski veri klasörünün kopyası ve eski paketler 7 gün saklanır, sonra kendiliğinden silinir; daha önce bu sayfadan kaldırabilirsiniz.'))
                    .' '.__('Yükseltme öncesi döküm 30 gün saklanır.'),
            ],
        ];
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
     * Serves a webmail application (install), turns webmail off (remove) or
     * brings SnappyMail to the agent's release (update); the mail server is
     * set up again.
     */
    private function webmail(string $id, string $action, MailSettings $mail): void
    {
        if (! $mail->isSetUp() || ($mail->server()['state'] ?? null) === 'installing' || ! in_array($action, ['install', 'update', 'remove'], true)) {
            return;
        }
        if ($action === 'install') {
            $mail->save(['webmail' => true, 'webmail_app' => $id]);
        } elseif ($action === 'remove') {
            if ($mail->servedWebmailApp() !== $id) {
                return;
            }
            $mail->save(['webmail' => false]);
        }
        $mail->saveServer(['state' => 'installing', 'error' => null]);
        SetupMailServer::dispatch();
        $name = MailSettings::WEBMAIL_APPS[$id];
        $this->dispatch('toast', message: match ($action) {
            'install' => __(':name açılıyor.', ['name' => $name]),
            'update' => __(':name güncelleniyor.', ['name' => $name]),
            default => __(':name kapatılıyor.', ['name' => $name]),
        });
    }

    /**
     * Title, text and button of the confirmation.
     *
     * @return array{title: string, text: string, button: string, danger: bool, candidate?: array{version: ?string, source: ?string}|null}
     */
    private function confirmText(Components $components, string $id, string $action): array
    {
        if ($id === SystemPackages::COMPONENT) {
            return $this->systemConfirmText($action, $this->confirming['packages'] ?? null, app(SystemPackages::class));
        }
        $name = $components->name($id);
        $row = collect($components->rows($components->meta($id)[2]) ?? [])->flatten(1)->firstWhere('id', $id) ?? [];
        $version = $row['latest'] ?? $row['version'] ?? null;

        return match ($action) {
            'upgrade' => $this->upgradeText($id, $row),
            'remove-old' => [
                'title' => __(':name silinsin mi?', ['name' => $row['kept']['name'] ?? $name]),
                'text' => ($id === 'postgresql'
                    ? __('Yükseltmeden kalan durdurulmuş eski küme silinir. Panelin kendi PostgreSQL\'i ve onun kullandığı paketler etkilenmez.')
                    : __('Yükseltmeden kalan eski veri klasörünün kopyası ve saklanan eski paketler silinir.'))
                    .' '.__('Bundan sonra eski sürüme dönülemez. Yükseltmeden önce alınan döküm yükseltmeden sonra 30 gün saklanır.'),
                'button' => __('Eski sürümü kaldır'),
                'danger' => true,
            ],
            'install' => [
                'title' => __(':name kurulsun mu?', ['name' => $name]),
                'text' => match (true) {
                    $id === 'roundcube' || $id === 'snappymail' => $this->webmailInstallText($id, $components),
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
                'text' => $id === 'snappymail'
                    ? __('Serfex Agent SnappyMail :version sürümünü GitHub\'dan indirir, imzasını denetler ve webmail adresinde açar. Kişiler ve ayarlar korunur; bir adım başarısız olursa önceki sürüm çalışmaya devam eder.', ['version' => $version ?? ''])
                    : __('Yeni sürüm:version kurulur ve hizmet yeniden başlatılır; birkaç saniyelik kesinti olabilir. Yeni sürüm çalışmazsa ayarlar geri alınır.', ['version' => ($version ? " ({$version})" : '')]),
                'button' => __('Güncelle'),
                'danger' => false,
            ],
            default => [
                'title' => __(':name kaldırılsın mı?', ['name' => $name]),
                'text' => match (true) {
                    $id === 'roundcube' || $id === 'snappymail' => __('Kullanıcılar postalarına tarayıcıdan ulaşamaz; posta programları çalışmaya devam eder.'),
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

    /**
     * Starts upgrading Ubuntu packages; the progress window shows it.
     *
     * @param  list<string>|null  $names
     */
    private function upgradePackages(?array $names, SystemPackages $packages): void
    {
        $result = $packages->start($names, auth()->user());
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $this->operationId = $result->id;
        $this->selected = [];
        $this->dispatch('toast', message: $names === null
            ? __('Ubuntu paketlerinin hepsi güncelleniyor.')
            : trans_choice(':count Ubuntu paketi güncelleniyor: :names', count($names), ['names' => implode(', ', array_slice($names, 0, 5)).(count($names) > 5 ? '…' : '')]));
    }

    /**
     * Restarts the server in a minute; the toast's Undo stops it.
     */
    private function reboot(AgentClient $agent, SystemPackages $packages): void
    {
        if (! auth()->user()?->isFullAdmin()) {
            $this->refused = __('Sunucuyu yalnızca tam yetkili yöneticiler yeniden başlatabilir.');

            return;
        }
        try {
            $agent->call('system.reboot', [], 30);
        } catch (AgentException $e) {
            $this->refused = match (true) {
                $e instanceof AgentUnavailableException => __('Sunucu ajanına ulaşılamıyor.'),
                $e->getCode() === -32005 => __('Bir yazılım işlemi sürüyor. Bitince yeniden deneyin.'),
                default => __('Sunucu yeniden başlatılamadı: :reason', ['reason' => $e->reason]),
            };

            return;
        }
        $packages->forget();
        $this->dispatch('toast',
            message: __('Sunucu 1 dakika içinde yeniden başlatılacak.'),
            source: $this->getId(),
            undo: ['method' => 'abortReboot', 'args' => []],
        );
    }

    /**
     * The confirmation of an Ubuntu package upgrade or a restart.
     *
     * @param  list<string>|null  $names
     * @return array{title: string, text: string, button: string, danger: bool, packages?: array<string, mixed>}
     */
    private function systemConfirmText(string $action, ?array $names, SystemPackages $packages): array
    {
        if ($action === 'reboot') {
            $reboot = $packages->list()['reboot'] ?? [];

            return [
                'title' => __('Sunucu yeniden başlatılsın mı?'),
                'text' => __('Sunucu 1 dakika sonra yeniden başlar. Siteler, posta ve panel birkaç dakika ulaşılamaz olur; hizmetler kendiliğinden yeniden açılır. O bir dakika içinde bildirimdeki Geri al ile vazgeçebilirsiniz.'),
                'button' => __('Yeniden başlat'),
                'danger' => true,
                'packages' => ['reboot' => $reboot['packages'] ?? []],
            ];
        }
        $rows = collect($packages->rows())->keyBy('name');
        $chosen = $names === null
            ? $rows->filter(fn ($r) => $r['selectable'] && ! $r['kept_back'])
            : $rows->only($names);
        $count = $names === null ? $chosen->count() : count($names);
        $components = $chosen->pluck('component_name')->filter()->unique()->values()->all();

        return [
            'title' => $names === null
                ? trans_choice(':count Ubuntu paketi güncellensin mi?', $count)
                : ($count === 1 ? __(':name güncellensin mi?', ['name' => $names[0]]) : trans_choice(':count Ubuntu paketi güncellensin mi?', $count)),
            'text' => __('Serfex Agent yeni sürümleri apt ile kurar. Hiçbir paket kaldırılmaz, sunucuda değiştirilmiş ayar dosyaları korunur ve güncellenen hizmetler kendiliğinden yeniden başlar; birkaç saniyelik kesinti olabilir. Güncellenen paketler geri alınamaz.'),
            'button' => __('Güncelle'),
            'danger' => false,
            'packages' => [
                'count' => $count,
                'security' => $chosen->where('security', true)->count(),
                'new' => $names === null ? $rows->where('new', true)->count() : 0,
                'components' => $components,
                'list' => $names ?? [],
            ],
        ];
    }

    /**
     * What turning on a webmail application does, also when it replaces
     * the other one.
     */
    private function webmailInstallText(string $id, Components $components): string
    {
        $mail = app(MailSettings::class);
        $served = $mail->servedWebmailApp();
        $text = $id === 'snappymail'
            ? __('Serfex Agent SnappyMail\'i GitHub\'dan indirir, imzasını denetler ve webmail adresinde açar. Posta sunucusu yeniden ayarlanır.')
            : __('Posta sunucusu yeniden ayarlanır ve webmail adresi açılır.');
        if ($served !== null && $served !== $id) {
            $text .= ' '.__(':other kapanır. Kişiler ve ayarlar taşınmaz; :other uygulamasınınkiler sunucuda kalır ve geri dönünce yerinde olur.', ['other' => MailSettings::WEBMAIL_APPS[$served]]);
        }

        return $text;
    }
}
