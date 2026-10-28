<?php

namespace App\Livewire\Settings;

use App\Enums\LaravelAppStatus;
use App\Enums\SiteStatus;
use App\Livewire\Concerns\InstallsAppTemplate;
use App\Models\AppTemplate;
use App\Models\GitAccount;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseServers;
use App\Services\Git\GitProviderException;
use App\Services\Laravel\AppTemplates;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One application template: Genel (source, version, database, processes,
 * update behaviour, who may install), Ortam (.env template and the
 * questions asked at install), Kurulum adımları (one-time setup commands,
 * first administrator, update steps) and Siteler (where it is installed,
 * their versions, updates and "Siteye kur").
 */
#[Layout('components.layouts.app')]
class AppTemplateShow extends Component
{
    use InstallsAppTemplate;
    use WithFileUploads;

    public const TABS = ['general', 'env', 'setup', 'sites'];

    #[Locked]
    public int $id;

    #[Url(except: 'general')]
    public string $tab = 'general';

    // Genel
    public string $name = '';

    public string $description = '';

    public string $gitAccountId = '';

    public string $refType = 'branch';

    public string $ref = '';

    public string $packageAsset = '';

    public string $packageUrl = '';

    public string $phpVersion = '';

    public string $database = 'mysql';

    public bool $frontend = true;

    public string $packageManager = 'npm';

    public bool $worker = true;

    public bool $scheduler = true;

    public bool $updateMaintenance = true;

    public bool $updateBackup = true;

    public bool $updateRollback = true;

    public bool $customersCanInstall = false;

    public string $healthPath = '/up';

    public string $versionFile = 'VERSION';

    /** @var list<string> */
    public array $phpVersions = [];

    // Ortam
    public string $envTemplate = '';

    /** @var list<array{key: string, label: string, default: string, required: bool, secret: bool, type: string}> */
    public array $fields = [];

    /** The question being edited: its index, -1 for a new one, null for none. */
    public ?int $fieldIndex = null;

    /** @var array{key: string, label: string, default: string, required: bool, secret: bool, type: string} */
    public array $fieldForm = ['key' => '', 'label' => '', 'default' => '', 'required' => false, 'secret' => false, 'type' => 'text'];

    // Kurulum adımları
    public string $setupCommands = '';

    public bool $createAdmin = false;

    public string $adminCommand = '';

    public bool $migrate = true;

    public string $beforeActivate = '';

    public string $afterActivate = '';

    // Siteler
    public string $search = '';

    public bool $confirmingAll = false;

    public bool $stopOnFailure = true;

    public bool $installing = false;

    public ?int $siteId = null;

    public bool $deleting = false;

    public function mount(AppTemplate $template, AgentClient $agent, AppTemplates $templates): void
    {
        $this->id = $template->id;
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'general';
        }
        try {
            $this->phpVersions = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException) {
            $this->phpVersions = [];
        }
        $templates->check($template);
        $this->load($template->fresh());
    }

    private function template(): AppTemplate
    {
        return AppTemplate::with('gitAccount')->findOrFail($this->id);
    }

    private function load(AppTemplate $t): void
    {
        $this->name = $t->name;
        $this->description = (string) $t->description;
        $this->gitAccountId = (string) ($t->git_account_id ?? '');
        $this->refType = $t->ref_type;
        $this->ref = (string) $t->ref;
        $this->packageAsset = (string) $t->package_asset;
        $this->packageUrl = (string) $t->package_url;
        $this->phpVersion = (string) $t->php_version;
        $this->database = $t->database;
        $this->frontend = $t->frontend;
        $this->packageManager = $t->package_manager ?: 'npm';
        $this->worker = $t->worker;
        $this->scheduler = $t->scheduler;
        $this->updateMaintenance = $t->update_maintenance;
        $this->updateBackup = $t->update_backup;
        $this->updateRollback = $t->update_rollback;
        $this->customersCanInstall = $t->customers_can_install;
        $this->healthPath = $t->health_path;
        $this->versionFile = $t->version_file;
        $this->envTemplate = (string) $t->env_template;
        $this->fields = $t->questions();
        $this->setupCommands = (string) $t->setup_commands;
        $this->createAdmin = $t->create_admin;
        $this->adminCommand = (string) $t->admin_command;
        $this->migrate = $t->migrate;
        $hooks = $t->hookScripts();
        $this->beforeActivate = $hooks['before_activate'];
        $this->afterActivate = $hooks['after_activate'];
    }

    public function show(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->resetValidation();
            $this->tab = $tab;
        }
    }

    /**
     * Throws away unsaved changes of the tab.
     */
    public function revert(): void
    {
        $this->resetValidation();
        $this->load($this->template());
    }

    public function saveGeneral(AppTemplates $templates): void
    {
        $t = $this->template();
        $package = $t->isPackage();
        $this->name = trim($this->name);
        $this->ref = trim($this->ref);
        $this->validate([
            'name' => ['required', 'max:60', Rule::unique('app_templates', 'name')->ignore($t->id)],
            'description' => ['nullable', 'max:300'],
            'gitAccountId' => ['nullable', Rule::in($this->accountChoices($t)->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'refType' => ['required', Rule::in(['branch', 'tag'])],
            'ref' => [$package ? 'nullable' : 'required', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
            'packageAsset' => [$package && ! $t->package_url ? 'required' : 'nullable', 'max:200', 'regex:~^[A-Za-z0-9*?._\[\]-]+$~'],
            'packageUrl' => [$package && $t->package_url ? 'required' : 'nullable', 'max:2048', 'url:https'],
            'phpVersion' => ['nullable', 'regex:/^\d\.\d{1,2}$/'],
            'database' => ['required', Rule::in(array_keys(AppTemplate::DATABASES))],
            'packageManager' => [Rule::in(LaravelApps::PACKAGE_MANAGERS)],
            'healthPath' => ['required', 'max:200', 'regex:~^/[A-Za-z0-9._~/?=&%-]*$~'],
            'versionFile' => ['required', 'max:200', 'regex:~^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$~', 'not_regex:~(^|/)\.\.(/|$)~'],
        ], [
            'ref.regex' => __('Dal veya etiket adı geçersiz.'),
            'healthPath.regex' => __('Sağlık adresi / ile başlayan bir yol olmalı.'),
        ], [
            'name' => __('ad'),
            'ref' => $this->refType === 'tag' ? __('etiket') : __('dal'),
            'packageAsset' => __('dosya adı'),
            'packageUrl' => __('paket adresi'),
            'healthPath' => __('sağlık adresi'),
            'versionFile' => __('sürüm dosyası'),
        ]);
        $versionChanged = $t->ref !== $this->ref || $t->ref_type !== $this->refType || $t->package_asset !== ($this->packageAsset ?: null) || (string) $t->git_account_id !== $this->gitAccountId;
        $t->update([
            'name' => $this->name,
            'description' => trim($this->description) ?: null,
            'git_account_id' => $this->gitAccountId !== '' ? (int) $this->gitAccountId : null,
            'ref_type' => $package ? 'tag' : $this->refType,
            'ref' => $package ? null : $this->ref,
            'package_asset' => $package && ! $t->package_url ? $this->packageAsset : null,
            'package_url' => $package && $t->package_url ? $this->packageUrl : null,
            'php_version' => $this->phpVersion ?: null,
            'database' => $this->database,
            'frontend' => $this->frontend,
            'package_manager' => $this->packageManager,
            'worker' => $this->worker,
            'scheduler' => $this->scheduler,
            'update_maintenance' => $this->updateMaintenance,
            'update_backup' => $this->updateBackup,
            'update_rollback' => $this->updateRollback,
            'customers_can_install' => $this->customersCanInstall,
            'health_path' => $this->healthPath,
            'version_file' => $this->versionFile,
        ]);
        if ($versionChanged) {
            $templates->check($t, force: true);
        }
        $this->dispatch('toast', message: __('Şablon kaydedildi. Bağlı siteler siz güncelleyene kadar değişmez.'), source: $this->getId());
    }

    public function saveEnv(): void
    {
        try {
            AppTemplates::parseEnv($this->envTemplate);
        } catch (InvalidArgumentException $e) {
            $this->addError('envTemplate', $e->getMessage());

            return;
        }
        $this->validate(['envTemplate' => ['nullable', 'max:8000']], [], ['envTemplate' => __('.env şablonu')]);
        $this->template()->update(['env_template' => trim($this->envTemplate) ?: null, 'fields' => array_values($this->fields)]);
        $this->dispatch('toast', message: __('.env şablonu kaydedildi; yeni kurulumlar bunu kullanır.'), source: $this->getId());
    }

    public function editField(int $index): void
    {
        $this->resetValidation();
        $this->fieldIndex = $index;
        $this->fieldForm = $this->fields[$index] ?? ['key' => '', 'label' => '', 'default' => '', 'required' => false, 'secret' => false, 'type' => 'text'];
        if ($index < 0 && count($this->fields) >= AppTemplate::MAX_FIELDS) {
            $this->fieldIndex = null;
            $this->dispatch('toast', message: __('En fazla :count soru sorulabilir.', ['count' => AppTemplate::MAX_FIELDS]), source: $this->getId());
        }
    }

    public function saveField(): void
    {
        $this->fieldForm['key'] = strtoupper(trim((string) $this->fieldForm['key']));
        $taken = collect($this->fields)->except($this->fieldIndex ?? -1)->pluck('key')->all();
        $this->validate([
            'fieldForm.key' => ['required', 'regex:/^[A-Z][A-Z0-9_]{0,39}$/', Rule::notIn($taken)],
            'fieldForm.label' => ['required', 'max:80'],
            'fieldForm.default' => ['nullable', 'max:200'],
            'fieldForm.type' => ['required', Rule::in(['text', 'file'])],
        ], [
            'fieldForm.key.regex' => __('Büyük harfle başlamalı; yalnızca A-Z, 0-9 ve _ (en fazla 40).'),
            'fieldForm.key.not_in' => __('Bu anahtar zaten var.'),
        ], [
            'fieldForm.key' => __('anahtar'),
            'fieldForm.label' => __('soru'),
            'fieldForm.default' => __('varsayılan'),
        ]);
        $field = [
            'key' => $this->fieldForm['key'],
            'label' => trim((string) $this->fieldForm['label']),
            'default' => $this->fieldForm['type'] === 'file' || $this->fieldForm['secret'] ? '' : trim((string) $this->fieldForm['default']),
            'required' => (bool) $this->fieldForm['required'],
            'secret' => $this->fieldForm['type'] === 'text' && (bool) $this->fieldForm['secret'],
            'type' => $this->fieldForm['type'],
        ];
        if ($this->fieldIndex !== null && $this->fieldIndex >= 0 && isset($this->fields[$this->fieldIndex])) {
            $this->fields[$this->fieldIndex] = $field;
        } else {
            $this->fields[] = $field;
        }
        $this->fieldIndex = null;
        $this->template()->update(['fields' => array_values($this->fields)]);
    }

    public function removeField(int $index): void
    {
        if (! isset($this->fields[$index])) {
            return;
        }
        $removed = $this->fields[$index];
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
        $this->fieldIndex = null;
        $this->template()->update(['fields' => $this->fields]);
        $this->dispatch('toast', message: __(':key sorusu kaldırıldı.', ['key' => $removed['key']]), source: $this->getId(),
            undo: ['method' => 'restoreField', 'args' => [$index, $removed]]);
    }

    /**
     * @param  array{key: string, label: string, default: string, required: bool, secret: bool, type: string}  $field
     */
    public function restoreField(int $index, array $field): void
    {
        $t = $this->template();
        $fields = $t->questions();
        if (collect($fields)->contains('key', $field['key']) || ! preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', (string) ($field['key'] ?? ''))) {
            return;
        }
        array_splice($fields, max(0, min($index, count($fields))), 0, [$field]);
        $t->update(['fields' => $fields]);
        $this->fields = $t->questions();
    }

    public function saveSetup(): void
    {
        $this->validate([
            'setupCommands' => ['nullable', 'max:4000'],
            'adminCommand' => [$this->createAdmin ? 'required' : 'nullable', 'max:1000'],
            'beforeActivate' => ['nullable', 'max:8000'],
            'afterActivate' => ['nullable', 'max:8000'],
        ], [
            'adminCommand.required' => __('Yönetici hesabını oluşturan komutu yazın.'),
        ]);
        foreach (['setupCommands' => $this->setupCommands, 'adminCommand' => $this->adminCommand] as $field => $text) {
            try {
                AppTemplates::parseCommands($text);
            } catch (InvalidArgumentException $e) {
                $this->addError($field, $e->getMessage());

                return;
            }
        }
        if (! $this->createAdmin && str_contains($this->setupCommands, '{admin.')) {
            $this->addError('setupCommands', __('Komutlar {admin.…} kullanıyor; önce "İlk yönetici hesabını oluştur"u açın.'));

            return;
        }
        if ($this->createAdmin && count(AppTemplates::parseCommands($this->adminCommand)) !== 1) {
            $this->addError('adminCommand', __('Tek satırlık bir komut yazın.'));

            return;
        }
        $t = $this->template();
        $t->update([
            'setup_commands' => trim($this->setupCommands) ?: null,
            'create_admin' => $this->createAdmin,
            'admin_command' => trim($this->adminCommand) ?: null,
            'migrate' => $this->migrate,
            'hooks' => ['before_build' => $t->hookScripts()['before_build'], 'before_activate' => $this->beforeActivate, 'after_activate' => $this->afterActivate],
        ]);
        $this->dispatch('toast', message: __('Kurulum adımları kaydedildi.'), source: $this->getId());
    }

    /**
     * Reads the version from the Git host now, and for a package template
     * the version each site runs.
     */
    public function check(AppTemplates $templates): void
    {
        $t = $this->template();
        $templates->check($t, force: true);
        if ($t->isPackage()) {
            $templates->readVersions($t);
        }
        $t->refresh();
        $this->dispatch('toast', message: $t->check_error ?? __('Sürüm bilgisi yenilendi.'), source: $this->getId());
    }

    public function export(AppTemplates $templates): StreamedResponse
    {
        $t = $this->template();
        $json = json_encode($templates->export($t), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($t->env_template) {
            $this->dispatch('toast', message: __('Dosya .env şablonundaki değerleri içerir; güvenli bir yerde saklayın.'), source: $this->getId());
        }

        return response()->streamDownload(fn () => print ($json), Str::slug($t->name ?: 'sablon').'.serfex-template.json', ['Content-Type' => 'application/json']);
    }

    public function delete(): void
    {
        $t = $this->template();
        $token = Str::random(32);
        Cache::put('app-template-undo:'.$token, [
            // As stored: the .env template stays encrypted.
            'attributes' => $t->getAttributes(),
            'apps' => $t->apps()->pluck('id')->all(),
        ], now()->addMinutes(10));
        $name = $t->name;
        $t->delete();
        session()->flash('toast', [
            'message' => __('":name" şablonu silindi. Kurulu uygulamalar çalışmaya devam eder.', ['name' => $name]),
            'undo' => ['name' => 'settings.app-template-index', 'method' => 'undoDelete', 'args' => [$token]],
        ]);
        $this->redirectRoute('settings.templates', navigate: true);
    }

    // Siteler

    public function updateApp(int $appId, AppTemplates $templates): void
    {
        $t = $this->template();
        $app = $t->apps()->with('site')->find($appId);
        if ($app === null || $t->isPackage() || $app->status !== LaravelAppStatus::Active || $app->hasRunningDeployment()) {
            return;
        }
        $templates->update($app, auth()->user());
        $this->dispatch('toast', message: __(':domain güncelleniyor.', ['domain' => $app->site->domain]), source: $this->getId());
    }

    public function updateAll(AppTemplates $templates): void
    {
        $this->confirmingAll = false;
        $count = $templates->updateAll($this->template(), $this->stopOnFailure, auth()->user());
        $this->dispatch('toast', message: $count > 0
            ? trans_choice(':count site sırayla güncelleniyor.', $count)
            : __('Güncellenecek site yok.'), source: $this->getId());
    }

    public function openInstall(): void
    {
        $this->resetValidation();
        $this->templateId = (string) $this->id;
        $this->siteId = $this->freeSites()->first()?->id;
        $this->prepareTemplateForm($this->siteId ? Site::find($this->siteId) : null);
        $this->installing = true;
    }

    public function updatedSiteId(): void
    {
        $this->prepareTemplateForm($this->siteId ? Site::with('systemUser.customer')->find($this->siteId) : null);
    }

    public function install(): void
    {
        $t = $this->template();
        $this->validate(['siteId' => ['required', Rule::in($this->freeSites()->pluck('id')->all())]] + $this->templateRules($t), [
            'siteId.required' => __('Bir site seçin.'),
        ], $this->templateAttributes($t));
        $site = Site::with('systemUser.customer')->findOrFail($this->siteId);
        if (($refusal = $this->templateRefusal($t, $site)) !== null) {
            $this->addError('siteId', $refusal);

            return;
        }
        $app = $this->startTemplateInstall($t, $site);
        session()->flash('toast', __('Kurulum başladı. Adımları bu sayfada canlı izleyebilirsiniz.'));
        $this->redirectRoute('laravel.show', $app, navigate: true);
    }

    /**
     * Active sites without a Laravel application.
     *
     * @return Collection<int, Site>
     */
    private function freeSites(): Collection
    {
        return Site::with('systemUser.customer')
            ->where('status', SiteStatus::Active)
            ->whereDoesntHave('laravelApp')
            ->whereDoesntHave('preview')
            ->orderBy('domain')
            ->get();
    }

    /**
     * The Git accounts the template can read with: the signed-in user's on
     * its host, and the one it has.
     *
     * @return Collection<int, GitAccount>
     */
    private function accountChoices(AppTemplate $t): Collection
    {
        return GitAccount::where('provider', $t->provider)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->when($t->git_account_id, fn ($q) => $q->orWhere('id', $t->git_account_id)))
            ->get();
    }

    public function render(AppTemplates $templates): View
    {
        $t = $this->template();
        $apps = collect();
        $refs = [];
        if ($this->tab === 'sites') {
            $apps = $t->apps()->with('site.systemUser.customer', 'latestDeployment')
                ->when($this->search !== '', fn ($q) => $q->whereHas('site', fn ($s) => $s->where('domain', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%')))
                ->get()->sortBy(fn (LaravelApp $a) => $a->site->domain)->values();
        }
        if ($this->tab === 'general' && ! $t->isPackage()) {
            try {
                $refs = Cache::remember("app-template-refs:{$t->id}:{$this->refType}", now()->addMinutes(5), fn () => $templates->refs($t, $this->refType));
            } catch (GitProviderException) {
                $refs = [];
            }
        }
        $behind = $t->apps()->get(['id', 'template_version'])->filter(fn ($a) => $t->isBehind($a));

        return view('livewire.settings.app-template', [
            'template' => $t,
            'apps' => $apps,
            'behind' => $behind,
            'busy' => $apps->contains(fn (LaravelApp $a) => $a->status->isBusy() || $a->latestDeployment?->status->isBusy()),
            'refs' => $refs,
            'accounts' => $this->accountChoices($t),
            'dbServers' => app(DatabaseServers::class)->installed(),
            'sites' => $this->installing ? $this->freeSites() : collect(),
            'picked' => $this->installing ? $t : null,
            'summary' => $this->installing ? $this->templateSummary($t, $this->siteId ? Site::with('systemUser')->find($this->siteId) : null) : '',
        ])->title(__(':name · Uygulama şablonları · Serfex', ['name' => $t->name]));
    }
}
