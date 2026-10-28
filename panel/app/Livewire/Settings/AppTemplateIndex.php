<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\PicksGitRepository;
use App\Models\AppTemplate;
use App\Models\LaravelApp;
use App\Services\Laravel\AppTemplates;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Araçlar ve Ayarlar → Uygulama şablonları: an administrator's own Laravel
 * applications, described once and installed on any site. Adding one
 * asks only where its code comes from; the rest is set on its page.
 */
#[Layout('components.layouts.app')]
#[Title('Uygulama şablonları · Serfex')]
class AppTemplateIndex extends Component
{
    use PicksGitRepository;
    use WithFileUploads;

    public bool $adding = false;

    public string $name = '';

    /** git or package */
    public string $source = 'git';

    public string $provider = 'github';

    /** Type the repository address although the host's list could be used. */
    public bool $typeAddress = false;

    public string $repository = '';

    public bool $private = false;

    public string $refType = 'branch';

    /** The branch or tag (the picker fills it with the default branch). */
    public string $branch = 'main';

    public string $packageAsset = '*.zip';

    public string $packageUrl = '';

    public bool $importing = false;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(): void
    {
        // Back from connecting a Git account: the form as it was left.
        $provider = request()->query('provider');
        if (is_string($provider) && isset(AppTemplate::PROVIDERS[$provider])) {
            $this->openAdd();
            $this->provider = $provider;
            $this->source = request()->query('source') === 'package' ? 'package' : 'git';
        }
    }

    protected function pickingFrom(): ?string
    {
        return $this->adding && ! $this->typeAddress && isset($this->gitHosts()[$this->provider]) ? $this->provider : null;
    }

    protected function pickerReturnPath(string $provider): string
    {
        return route('settings.templates', ['provider' => $provider, 'source' => $this->source], false);
    }

    public function openAdd(): void
    {
        $this->reset('name', 'source', 'provider', 'typeAddress', 'repository', 'private', 'refType', 'branch', 'packageAsset', 'packageUrl');
        $this->resetPicker();
        $this->resetValidation();
        $this->adding = true;
    }

    public function updatedProvider(): void
    {
        $this->resetPicker();
        $this->typeAddress = false;
    }

    public function updatedSource(): void
    {
        // A package without a Git host is downloaded from an address.
        $this->resetValidation();
    }

    public function add(AppTemplates $templates): void
    {
        $picked = null;
        if ($this->pickingFrom() !== null) {
            $picked = $this->pickedRepository();
            if ($picked === null) {
                return;
            }
        }
        $this->name = trim($this->name);
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->packageUrl = trim($this->packageUrl);
        $package = $this->source === 'package';
        $byUrl = $package && $this->provider === 'other';
        $this->validate([
            'name' => ['required', 'max:60', Rule::unique('app_templates', 'name')],
            'source' => ['required', Rule::in(AppTemplate::SOURCES)],
            'provider' => ['required', Rule::in(array_keys(AppTemplate::PROVIDERS))],
            'repository' => [$picked === null && ! $byUrl ? 'required' : 'nullable', 'max:300', 'regex:'.LaravelApps::REPOSITORY_PATTERN],
            'refType' => ['required', Rule::in(['branch', 'tag'])],
            'branch' => [$package ? 'nullable' : 'required', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
            'packageAsset' => [$package && ! $byUrl ? 'required' : 'nullable', 'max:200', 'regex:~^[A-Za-z0-9*?._\[\]-]+$~'],
            'packageUrl' => [$byUrl ? 'required' : 'nullable', 'max:2048', 'url:https'],
        ], [
            'name.unique' => __('Bu adda bir şablon zaten var.'),
            'repository.required' => __('Depo adresini girin.'),
            'repository.regex' => __('Depo adresi "kullanıcı/depo", https://… veya git@… biçiminde olmalı.'),
            'branch.required' => __('Dal veya etiket adını girin.'),
            'branch.regex' => __('Dal veya etiket adı geçersiz.'),
            'packageAsset.regex' => __('Dosya adı kalıbı yalnızca harf, rakam, . - _ ve * içerebilir.'),
        ], [
            'name' => __('ad'),
            'packageAsset' => __('dosya adı'),
            'packageUrl' => __('paket adresi'),
        ]);

        $template = AppTemplate::create([
            'name' => $this->name,
            'source' => $this->source,
            'provider' => $this->provider,
            'git_account_id' => $picked['account']->id ?? null,
            'repository' => $picked !== null ? $picked['repo']['name'] : ($byUrl ? null : $this->repository),
            'remote_repository' => $picked['repo']['name'] ?? null,
            // A repository picked from the list is fetched with each
            // site's deploy key, like the Laravel install does.
            'private' => $picked !== null || $this->private,
            'ref_type' => $package ? 'tag' : $this->refType,
            'ref' => $package ? null : $this->branch,
            'package_asset' => $package && ! $byUrl ? $this->packageAsset : null,
            'package_url' => $byUrl ? $this->packageUrl : null,
            // Laravel's own default of a new application.
            'database' => 'mysql',
            'package_manager' => 'npm',
            'migrate' => ! $package,
            'frontend' => ! $package,
        ]);
        $templates->check($template, force: true);

        session()->flash('toast', __('":name" şablonu oluşturuldu. Veritabanını, .env\'yi ve kurulum adımlarını buradan ayarlayın.', ['name' => $template->name]));
        $this->redirectRoute('settings.templates.show', $template, navigate: true);
    }

    public function openImport(): void
    {
        $this->reset('file');
        $this->resetValidation();
        $this->importing = true;
    }

    public function import(AppTemplates $templates): void
    {
        $this->validate(['file' => ['required', 'file', 'max:256']], [], ['file' => __('dosya')]);
        $data = json_decode((string) file_get_contents($this->file->getRealPath()), true);
        try {
            $template = $templates->import(is_array($data) ? $data : []);
        } catch (InvalidArgumentException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }
        $this->importing = false;
        session()->flash('toast', __('":name" içe aktarıldı. Git hesabını ve sürümü kontrol edin.', ['name' => $template->name]));
        $this->redirectRoute('settings.templates.show', $template, navigate: true);
    }

    /**
     * Undo for "Şablonu sil": the template again, with its installs
     * linked to it as before.
     */
    public function undoDelete(string $token): void
    {
        $saved = Cache::pull('app-template-undo:'.$token);
        if (! is_array($saved) || AppTemplate::whereKey($saved['attributes']['id'] ?? 0)->exists()) {
            return;
        }
        $template = new AppTemplate;
        $template->setRawAttributes($saved['attributes']);
        $template->save();
        LaravelApp::whereIn('id', $saved['apps'] ?? [])->whereNull('app_template_id')->update(['app_template_id' => $template->id]);
        $this->dispatch('toast', message: __('":name" geri getirildi.', ['name' => $template->name]), source: $this->getId());
    }

    public function render(): View
    {
        $templates = AppTemplate::with('gitAccount')->withCount('apps')->orderByRaw('lower(name)')->get();
        $behind = [];
        foreach ($templates as $t) {
            $behind[$t->id] = $t->apps()->get(['id', 'template_version'])->filter(fn ($a) => $t->isBehind($a))->count();
        }

        return view('livewire.settings.app-templates', [
            'templates' => $templates,
            'behind' => $behind,
            'picker' => $this->adding ? $this->picker() : null,
            'hosts' => $this->adding ? $this->gitHosts() : [],
        ]);
    }
}
