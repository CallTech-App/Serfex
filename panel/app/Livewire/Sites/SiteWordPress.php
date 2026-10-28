<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Models\WordPressInstall;
use App\Models\WordPressOperation;
use App\Services\Agent\AgentException;
use App\Services\Sites\WordPressSites;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * WordPress araç seti of a site: install WordPress into the web root or
 * manage the one found there; update it, its plugins and themes behind a
 * restore point; sign in to its dashboard; maintenance mode; hardening.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteWordPress extends Component
{
    public int $siteId;

    #[Url(except: 'plugins')]
    public string $tab = 'plugins';

    /** @var list<array{path: string, version: string}>|null */
    public ?array $found = null;

    public ?string $agentError = null;

    /** True once this visit has read the installation from the agent. */
    public bool $loaded = false;

    public bool $installing = false;

    public string $title = '';

    public string $adminUser = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $locale = 'tr_TR';

    public bool $harden = true;

    /** @var array{core: bool, plugins: list<string>, themes: list<string>}|null */
    public ?array $pending = null;

    public ?int $logId = null;

    public ?int $restoreId = null;

    public bool $detaching = false;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
        if (! in_array($this->tab, ['plugins', 'themes', 'security', 'history'], true)) {
            $this->tab = 'plugins';
        }
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser', 'wordpress', 'laravelApp')->findOrFail($this->siteId);
    }

    private function wp(): ?WordPressInstall
    {
        $site = $this->site();

        return $site->wordpress?->setRelation('site', $site);
    }

    private function active(): ?WordPressInstall
    {
        $wp = $this->wp();

        return $wp !== null && $wp->isActive() && ! $wp->hasRunningOperation() ? $wp : null;
    }

    /**
     * Runs on page load: reads the installation, or looks for one.
     */
    public function loadStatus(WordPressSites $wordpress): void
    {
        $this->loaded = true;
        $wp = $this->wp();
        if ($wp === null || $wp->status === WordPressInstall::FAILED) {
            $this->searchInstalls($wordpress);

            return;
        }
        if ($wp->isActive()) {
            $this->agentError = $wordpress->refresh($wp);
        }
    }

    public function refreshStatus(WordPressSites $wordpress): void
    {
        $wp = $this->wp();
        if ($wp?->isActive()) {
            $this->agentError = $wordpress->refresh($wp);
            if ($this->agentError === null) {
                $this->dispatch('toast', message: __('WordPress denetlendi.'));
            }
        }
    }

    public function searchInstalls(WordPressSites $wordpress): void
    {
        try {
            $this->found = $wordpress->scan($this->site());
            $this->agentError = null;
        } catch (AgentException $e) {
            $this->found = [];
            $this->agentError = $e->reason ?: $e->getMessage();
        }
    }

    /**
     * Manage a WordPress found on the site.
     */
    public function adopt(int $index, WordPressSites $wordpress): void
    {
        $site = $this->site();
        $f = $this->found[$index] ?? null;
        if ($f === null || ($site->wordpress !== null && $site->wordpress->status !== WordPressInstall::FAILED)) {
            return;
        }
        $site->wordpress?->delete();
        $wordpress->adopt($site, $f['path']);
        $this->found = null;
        $this->dispatch('toast', message: __(':path klasöründeki WordPress artık Serfex\'ten yönetiliyor.', ['path' => $f['path']]),
            source: $this->getId(), undo: ['method' => 'detachQuietly', 'args' => []]);
    }

    public function openInstall(WordPressSites $wordpress): void
    {
        $site = $this->site();
        if (($refusal = $wordpress->installRefusal($site)) !== null) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->title = $site->domain;
        $this->adminUser = $site->systemUser->name;
        $this->adminEmail = (string) auth()->user()->email;
        $this->adminPassword = Str::password(20, symbols: false);
        $this->locale = app()->getLocale() === 'tr' ? 'tr_TR' : 'en_US';
        $this->harden = true;
        $this->installing = true;
    }

    public function newPassword(): void
    {
        $this->adminPassword = Str::password(20, symbols: false);
    }

    public function install(WordPressSites $wordpress): void
    {
        $site = $this->site();
        if (($site->wordpress !== null && $site->wordpress->status !== WordPressInstall::FAILED) || ($refusal = $wordpress->installRefusal($site)) !== null) {
            $this->installing = false;
            if (isset($refusal)) {
                $this->dispatch('toast', message: $refusal);
            }

            return;
        }
        $this->title = trim($this->title);
        $this->adminUser = trim($this->adminUser);
        $this->adminEmail = trim($this->adminEmail);
        $this->validate([
            'title' => ['required', 'max:200', 'not_regex:/[\r\n]/'],
            'adminUser' => ['required', 'max:60', 'regex:/^[A-Za-z0-9_.@-]+$/', 'not_in:admin,administrator,Admin,root'],
            'adminEmail' => ['required', 'email', 'max:100'],
            'adminPassword' => ['required', 'min:10', 'max:200', 'not_regex:/[\r\n]/'],
            'locale' => ['required', 'in:tr_TR,en_US'],
        ], [
            'adminUser.regex' => __('Harf, rakam ve _ . @ - kullanın.'),
            'adminUser.not_in' => __('Bu ad ilk denenen addır; başka bir ad seçin.'),
        ], [
            'title' => __('site adı'), 'adminUser' => __('kullanıcı adı'), 'adminEmail' => __('e-posta'), 'adminPassword' => __('parola'),
        ]);
        try {
            $wordpress->install($site, [
                'title' => $this->title, 'admin_user' => $this->adminUser, 'admin_email' => $this->adminEmail,
                'admin_password' => $this->adminPassword, 'locale' => $this->locale, 'harden' => $this->harden,
            ], auth()->user());
        } catch (AgentException $e) {
            $this->addError('title', __('Veritabanı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $this->installing = false;
        $this->reset('adminPassword');
        $this->dispatch('toast', message: __(':domain sitesine WordPress kuruluyor.', ['domain' => $site->domain]));
    }

    /**
     * Opens the update dialog: all (everything with an update), core, or
     * one plugin or theme.
     */
    public function confirmUpdate(string $what, string $name = ''): void
    {
        $wp = $this->active();
        if ($wp === null) {
            return;
        }
        $plugins = collect($wp->items('plugins'))->filter(fn ($i) => ($i['update'] ?? '') !== '');
        $themes = collect($wp->items('themes'))->filter(fn ($i) => ($i['update'] ?? '') !== '');
        $this->pending = match ($what) {
            'all' => ['core' => $wp->core_update !== null, 'plugins' => $plugins->pluck('name')->all(), 'themes' => $themes->pluck('name')->all()],
            'core' => ['core' => $wp->core_update !== null, 'plugins' => [], 'themes' => []],
            'plugins' => ['core' => false, 'plugins' => $plugins->pluck('name')->all(), 'themes' => []],
            'plugin' => ['core' => false, 'plugins' => $plugins->where('name', $name)->pluck('name')->all(), 'themes' => []],
            'theme' => ['core' => false, 'plugins' => [], 'themes' => $themes->where('name', $name)->pluck('name')->all()],
            default => null,
        };
        if ($this->pending !== null && ! $this->pending['core'] && $this->pending['plugins'] === [] && $this->pending['themes'] === []) {
            $this->pending = null;
        }
    }

    public function update(WordPressSites $wordpress): void
    {
        $wp = $this->active();
        $pending = $this->pending;
        $this->pending = null;
        if ($wp === null || $pending === null || $wp->site->isSuspended()) {
            return;
        }
        $operation = $wordpress->update($wp, (bool) $pending['core'], $pending['plugins'], $pending['themes'], auth()->user());
        $this->logId = $operation->id;
        $this->dispatch('toast', message: __(':domain WordPress güncellemesi başladı; önce bir geri dönüş noktası alınıyor.', ['domain' => $wp->site->domain]));
    }

    public function showLog(int $id): void
    {
        $this->logId = $this->wp()?->operations()->find($id)?->id;
    }

    public function closeLog(): void
    {
        $this->logId = null;
    }

    public function confirmRestore(int $id, WordPressSites $wordpress): void
    {
        $wp = $this->active();
        $this->restoreId = $wp !== null && $wordpress->restorable($wp)?->id === $id ? $id : null;
    }

    public function restore(WordPressSites $wordpress): void
    {
        $wp = $this->active();
        $id = $this->restoreId;
        $this->restoreId = null;
        $update = $wp !== null ? $wordpress->restorable($wp) : null;
        if ($update === null || $update->id !== $id) {
            return;
        }
        $operation = $wordpress->restore($wp, $update, auth()->user());
        $this->logId = $operation->id;
        $this->dispatch('toast', message: __(':domain WordPress güncelleme öncesine döndürülüyor.', ['domain' => $wp->site->domain]));
    }

    public function setMaintenance(bool $on, WordPressSites $wordpress): void
    {
        $wp = $this->active();
        if ($wp === null) {
            return;
        }
        try {
            $wordpress->maintenance($wp, $on);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Bakım modu değiştirilemedi: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $this->dispatch('toast', message: $on ? __('WordPress bakım modunda: ziyaretçiler "kısa süreli bakım" sayfasını görüyor.') : __('WordPress bakım modundan çıktı.'),
            source: $this->getId(), undo: ['method' => 'setMaintenance', 'args' => [! $on]]);
    }

    /**
     * Activates or deactivates a plugin, or activates a theme.
     */
    public function switchItem(string $kind, string $name, string $action, WordPressSites $wordpress): void
    {
        $wp = $this->active();
        if ($wp === null || ! in_array($kind, ['plugin', 'theme'], true) || ! in_array($action, ['activate', 'deactivate'], true)) {
            return;
        }
        $before = collect($wp->items($kind.'s'))->firstWhere('name', $name);
        $previousTheme = $kind === 'theme' ? collect($wp->items('themes'))->firstWhere('status', 'active')['name'] ?? null : null;
        try {
            $item = $wordpress->item($wp, $kind, $name, $action);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Değiştirilemedi: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $label = $item['title'] ?? ($before['title'] ?? $name);
        $undo = match (true) {
            $kind === 'plugin' => ['method' => 'switchItem', 'args' => ['plugin', $name, $action === 'activate' ? 'deactivate' : 'activate']],
            $previousTheme !== null && $previousTheme !== $name => ['method' => 'switchItem', 'args' => ['theme', $previousTheme, 'activate']],
            default => null,
        };
        $message = match (true) {
            $kind === 'theme' => __(':name teması etkinleştirildi.', ['name' => $label]),
            $action === 'activate' => __(':name eklentisi etkinleştirildi.', ['name' => $label]),
            default => __(':name eklentisi devre dışı bırakıldı.', ['name' => $label]),
        };
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: $undo);
    }

    public function setAutoUpdate(string $policy, WordPressSites $wordpress): void
    {
        $wp = $this->active();
        if ($wp === null || ! in_array($policy, WordPressSites::AUTO_UPDATES, true)) {
            return;
        }
        $before = (string) ($wp->state['auto_update_core'] ?? 'minor');
        try {
            $wordpress->autoUpdate($wp, $policy);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Değiştirilemedi: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $this->dispatch('toast', message: __('Otomatik güncellemeler: :policy.', ['policy' => self::autoUpdateLabels()[$policy]]),
            source: $this->getId(), undo: ['method' => 'setAutoUpdate', 'args' => [$before]]);
    }

    /**
     * Turns one hardening rule on or off.
     */
    public function setRule(string $rule, bool $on, WordPressSites $wordpress): void
    {
        $this->applyRules([$rule => $on], $wordpress);
    }

    public function hardenRecommended(WordPressSites $wordpress): void
    {
        $this->applyRules(array_fill_keys(WordPressSites::RECOMMENDED, true), $wordpress);
    }

    /**
     * Applies rules (name => on); Undo applies the earlier state of the
     * same rules.
     *
     * @param  array<string, bool>  $rules
     */
    public function applyRules(array $rules, WordPressSites $wordpress): void
    {
        $wp = $this->active();
        $rules = array_intersect_key(array_map(fn ($v) => (bool) $v, $rules), array_flip(WordPressSites::RULES));
        if ($wp === null || $rules === []) {
            return;
        }
        if ($wp->webPath() === null) {
            $rules = array_diff_key($rules, array_flip(WordPressSites::NGINX_RULES));
        }
        $now = (array) ($wp->state['hardening'] ?? []);
        $before = array_map(fn ($r) => (bool) ($now[$r] ?? false), array_combine(array_keys($rules), array_keys($rules)));
        try {
            $wordpress->harden($wp, $rules);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Güvenlik ayarı uygulanamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $on = count(array_filter($rules));
        $message = count($rules) === 1
            ? ($on ? __(':rule: açıldı.', ['rule' => self::ruleLabels()[array_key_first($rules)][0]]) : __(':rule: kapatıldı.', ['rule' => self::ruleLabels()[array_key_first($rules)][0]]))
            : __('Önerilen güvenlik ayarları uygulandı.');
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'applyRules', 'args' => [$before]]);
    }

    public function detach(WordPressSites $wordpress): void
    {
        $this->detaching = false;
        $wp = $this->active();
        if ($wp === null) {
            return;
        }
        $path = $wp->path;
        try {
            $wordpress->detach($wp);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Yönetimden çıkarılamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $this->found = null;
        $this->dispatch('toast', message: __('WordPress yönetimden çıkarıldı; dosyaları :path klasöründe duruyor.', ['path' => $path]),
            source: $this->getId(), undo: ['method' => 'reattach', 'args' => [$path]]);
    }

    /**
     * Undo for "Yönetimden çıkar": manage the same folder again.
     */
    public function reattach(string $path, WordPressSites $wordpress): void
    {
        $site = $this->site();
        if ($site->wordpress === null && preg_match('~^[A-Za-z0-9_][A-Za-z0-9._/-]*$~', $path) && ! str_contains($path, '..')) {
            $wordpress->adopt($site, $path);
        }
    }

    /**
     * Undo for "Yönet": stop managing it again.
     */
    public function detachQuietly(WordPressSites $wordpress): void
    {
        $wp = $this->active();
        if ($wp !== null) {
            try {
                $wordpress->detach($wp);
            } catch (AgentException) {
                $wp->delete();
            }
        }
    }

    /**
     * The log of an operation from $offset on, for <x-run-log>.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $wp = $this->wp();

        return $wp === null ? null : app(WordPressSites::class)->read($wp, $run, $offset);
    }

    /**
     * @return array<string, string>
     */
    public static function autoUpdateLabels(): array
    {
        return [
            'minor' => __('Yalnızca güvenlik ve bakım sürümleri'),
            'all' => __('Tüm yeni sürümler'),
            'off' => __('Kapalı'),
        ];
    }

    /**
     * Each rule's name and what it does.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ruleLabels(): array
    {
        return [
            'file_edit' => [__('Dosya düzenleyiciyi kapat'), __('Yönetim panelinden eklenti ve tema dosyaları düzenlenemez; ele geçirilen bir hesap koda dokunamaz.')],
            'config_perms' => [__('wp-config.php\'yi koru'), __('Ayar dosyası (veritabanı parolası) yalnızca sitenin kullanıcısı tarafından okunur.')],
            'uploads_php' => [__('Yüklemelerde PHP çalıştırma'), __('wp-content/uploads içine konan PHP dosyaları çalışmaz; arka kapı yüklemelerini durdurur.')],
            'sensitive_files' => [__('Hassas dosyaları gizle'), __('wp-config.php, readme.html, license.txt ve debug.log dışarıdan açılamaz.')],
            'xmlrpc' => [__('XML-RPC\'yi kapat'), __('Parola deneme saldırılarında kullanılır. WordPress mobil uygulaması ve Jetpack kullanıyorsanız açık bırakın.')],
        ];
    }

    public function render(WordPressSites $wordpress): View
    {
        $site = $this->site();
        $wp = $site->wordpress?->setRelation('site', $site);
        $operations = $wp ? $wp->operations()->limit(30)->get() : collect();
        $state = (array) ($wp->state ?? []);

        return view('livewire.sites.wordpress', [
            'site' => $site,
            'wp' => $wp,
            'state' => $state,
            'plugins' => $wp ? $wp->items('plugins') : [],
            'themes' => $wp ? $wp->items('themes') : [],
            'operations' => $operations,
            'busy' => $operations->contains(fn (WordPressOperation $o) => $o->status->isBusy()),
            'installOp' => $wp?->status === WordPressInstall::INSTALLING || $wp?->status === WordPressInstall::FAILED ? $operations->firstWhere('kind', 'install') : null,
            'restorable' => $wp?->isActive() ? $wordpress->restorable($wp) : null,
            'refusal' => $wp === null || $wp->status === WordPressInstall::FAILED ? $wordpress->installRefusal($site) : null,
            'databaseName' => $wp === null || $wp->status === WordPressInstall::FAILED ? $wordpress->databaseName($site) : null,
            'log' => $this->logId ? $operations->firstWhere('id', $this->logId) : null,
            'restoreOp' => $this->restoreId ? $operations->firstWhere('id', $this->restoreId) : null,
            'rules' => self::ruleLabels(),
        ])->title(__('WordPress · :domain · Serfex', ['domain' => $site->domain]));
    }
}
