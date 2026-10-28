<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Concerns\PicksGitRepository;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\ReadsRunLogs;
use App\Models\LaravelDeployment;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviders;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Deploy settings, the release history with its logs, returning to an
 * earlier release, hooks, the deploy key and the webhook.
 */
class Deployments extends Section
{
    protected const VIEW_METHODS = ['showLog', 'closeLog'];

    protected const MANAGE_METHODS = ['openConnect', 'connect', 'refreshRepositories'];

    use PicksGitRepository;
    use ReadsRunLogs;

    public ?int $logId = null;

    public ?int $rollbackId = null;

    public bool $editingHooks = false;

    /** @var array{before_build: string, before_activate: string, after_activate: string} */
    public array $hooks = ['before_build' => '', 'before_activate' => '', 'after_activate' => ''];

    public bool $showingKey = false;

    public ?string $publicKey = null;

    public bool $connecting = false;

    public string $provider = 'github';

    public string $repository = '';

    public string $branch = 'main';

    public bool $private = false;

    /** Type the repository address although the host's list could be used. */
    public bool $typeAddress = false;

    /** What the host refused while linking the picked repository. */
    public array $linkErrors = [];

    public string $healthPath = '/up';

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->hooks = $app->hookScripts();
        $this->healthPath = $app->health_path;
        // Back from connecting a Git account: the form as it was left.
        $provider = request()->query('connect');
        if (is_string($provider) && isset(LaravelIndex::PROVIDERS[$provider]) && $this->can('manage')) {
            $this->openConnect();
            $this->provider = $provider;
        }
    }

    protected function pickingFrom(): ?string
    {
        return $this->connecting && ! $this->typeAddress && isset($this->gitHosts()[$this->provider]) ? $this->provider : null;
    }

    protected function pickerReturnPath(string $provider): string
    {
        return route('laravel.show', [$this->appId, 'section' => 'deployments', 'connect' => $provider], false);
    }

    public function updatedProvider(): void
    {
        $this->resetPicker();
        $this->typeAddress = false;
    }

    #[On('show-deploy-log')]
    public function showLog(int $id): void
    {
        $this->logId = LaravelDeployment::where('laravel_app_id', $this->appId)->find($id)?->id;
    }

    public function closeLog(): void
    {
        $this->logId = null;
    }

    public function setting(string $name, mixed $value): void
    {
        $app = $this->app();
        match ($name) {
            'auto_deploy', 'auto_rollback', 'backup_before_deploy' => $app->update([$name => (bool) $value]),
            'keep_releases' => $app->update(['keep_releases' => max(2, min(20, (int) $value))]),
            default => null,
        };
        $this->dispatch('toast', message: __('Dağıtım ayarı kaydedildi.'));
    }

    public function saveHealthPath(): void
    {
        $this->healthPath = '/'.ltrim(trim($this->healthPath), '/');
        $this->validate(['healthPath' => ['required', 'max:200', 'regex:~^/[^\s#]*$~']], [], ['healthPath' => __('sağlık adresi')]);
        $this->app()->update(['health_path' => $this->healthPath]);
        $this->dispatch('toast', message: __('Sağlık kontrolü adresi kaydedildi.'));
    }

    public function saveHooks(): void
    {
        $this->validate([
            'hooks.before_build' => ['nullable', 'string', 'max:20000'],
            'hooks.before_activate' => ['nullable', 'string', 'max:20000'],
            'hooks.after_activate' => ['nullable', 'string', 'max:20000'],
        ]);
        $app = $this->app();
        $before = $app->hookScripts();
        $app->update(['hooks' => array_map(fn ($s) => str_replace("\r\n", "\n", trim((string) $s)), $this->hooks)]);
        $this->dispatch('toast',
            message: __('Dağıtım adımları kaydedildi; bir sonraki dağıtımda çalışır.'),
            source: $this->getId(),
            undo: ['method' => 'restoreHooks', 'args' => [$before]],
        );
    }

    /**
     * @param  array<string, string>  $hooks
     */
    public function restoreHooks(array $hooks): void
    {
        $hooks = array_intersect_key($hooks, $this->hooks);
        $this->app()->update(['hooks' => $hooks]);
        $this->hooks = $this->app()->hookScripts();
    }

    public function confirmRollback(int $id): void
    {
        $this->rollbackId = LaravelDeployment::where('laravel_app_id', $this->appId)->find($id)?->id;
    }

    public function rollback(LaravelApps $apps): void
    {
        $app = $this->app();
        $to = $this->rollbackId ? LaravelDeployment::where('laravel_app_id', $app->id)->find($this->rollbackId) : null;
        $this->rollbackId = null;
        if ($to === null || $to->release === null || $app->hasRunningDeployment()) {
            return;
        }
        $previous = $app->deployments()->where('release', $app->current_release)->first();
        $deployment = $apps->rollback($app, $to, auth()->user());
        $this->dispatch('toast',
            message: __('#:number sürümüne dönülüyor.', ['number' => $to->number]),
            source: $this->getId(),
            undo: $previous ? ['method' => 'undoRollback', 'args' => [$deployment->id, $previous->id]] : null,
        );
    }

    /**
     * Undo for a return to an earlier release: back to the release that was
     * live before it.
     */
    public function undoRollback(int $rollbackId, int $previousId, LaravelApps $apps): void
    {
        $app = $this->app();
        $previous = LaravelDeployment::where('laravel_app_id', $app->id)->find($previousId);
        if ($previous === null) {
            return;
        }
        $rollback = LaravelDeployment::find($rollbackId);
        if ($rollback?->status->isBusy()) {
            // Not started yet: it simply does not run.
            $rollback->delete();

            return;
        }
        $apps->rollback($app, $previous, auth()->user());
    }

    public function showKey(): void
    {
        $app = $this->app();
        $key = $this->agent('laravel.key', $app->target());
        if ($key !== null) {
            $this->publicKey = $key['public_key'] ?? null;
            $app->update(['deploy_key' => $this->publicKey]);
            $this->showingKey = true;
        }
    }

    public function openConnect(): void
    {
        $this->reset('provider', 'repository', 'branch', 'private', 'typeAddress');
        $this->resetPicker();
        $this->resetValidation();
        $this->connecting = true;
    }

    /**
     * Attach a Git repository to an application installed as a new project
     * or found on the site, so it can be deployed.
     */
    public function connect(GitAccounts $accounts, GitProviders $providers): void
    {
        // A package install updates itself; a second updater would fight it.
        if ($this->app()->isPackage()) {
            $this->connecting = false;

            return;
        }
        if ($this->pickingFrom() !== null) {
            $this->connectPicked($accounts, $providers);

            return;
        }
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->validate([
            'provider' => ['required', 'in:'.implode(',', array_keys(LaravelIndex::PROVIDERS))],
            'repository' => ['required', 'max:300', 'regex:'.LaravelApps::REPOSITORY_PATTERN],
            'branch' => ['nullable', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
        ], [
            'repository.regex' => __('Depo adresi "kullanıcı/depo", https://… veya git@… biçiminde olmalı.'),
        ], ['repository' => 'depo', 'branch' => 'dal']);
        $host = LaravelIndex::PROVIDERS[$this->provider][1] ?? 'github.com';
        $repo = $this->repository;
        if (preg_match('~^[\w.-]+/[\w.-]+$~', $repo)) {
            $path = preg_replace('~\.git$~', '', $repo).'.git';
            $repo = $this->private ? "git@{$host}:{$path}" : "https://{$host}/{$path}";
        } elseif ($this->private && preg_match('~^https://([A-Za-z0-9.-]+)/(.+)$~', $repo, $m)) {
            $repo = "git@{$m[1]}:".preg_replace('~\.git$~', '', $m[2]).'.git';
        }
        $app = $this->app();
        if ($app->remote_repository !== null && $app->repository !== $repo) {
            // Another repository: the key and webhook added to the old one go.
            $accounts->unlink($app);
        }
        $app->update(['repository' => $repo, 'branch' => $this->branch ?: null]);
        $this->connecting = false;
        if ($this->private) {
            $this->showKey();
        }
        $this->dispatch('toast', message: __('Depo bağlandı. "Dağıt" ile ilk sürümü yayına alabilirsiniz.'));
    }

    /**
     * Connects the repository picked from the account's list: fetched
     * with the deploy key, which is added to it together with the webhook.
     */
    private function connectPicked(GitAccounts $accounts, GitProviders $providers): void
    {
        $picked = $this->pickedRepository();
        if ($picked === null) {
            return;
        }
        $this->branch = trim($this->branch);
        $this->validate(['branch' => ['nullable', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~']], [], ['branch' => 'dal']);
        $app = $this->app();
        $key = $this->agent('laravel.key', $app->target());
        if ($key === null) {
            return;
        }
        $accounts->unlink($app);
        $app->update(['repository' => $picked['repo']['ssh_url'], 'branch' => $this->branch ?: null, 'deploy_key' => $key['public_key'] ?? null]);
        $linked = $accounts->link($app, $picked['account'], $picked['repo']['name'], (string) ($key['public_key'] ?? ''), $providers->webhookUrl($app->webhook_token), pullRequests: true);
        $this->connecting = false;
        $this->linkErrors = $linked['errors'];
        $this->resetPicker();
        if (! $linked['key']) {
            $this->publicKey = $key['public_key'] ?? null;
            $this->showingKey = true;
        }
        $label = GitProviders::LABELS[$picked['account']->provider];
        $this->dispatch('toast', message: match (true) {
            $linked['key'] && $linked['hook'] => __('Depo bağlandı; dağıtım anahtarı ve webhook :provider\'a eklendi. "Dağıt" ile ilk sürümü yayına alabilirsiniz.', ['provider' => $label]),
            $linked['key'] => __('Depo bağlandı ve dağıtım anahtarı :provider\'a eklendi; webhook eklenemedi.', ['provider' => $label]),
            default => __('Depo bağlandı; dağıtım anahtarını depoya elle ekleyin.'),
        });
    }

    public function render(): View
    {
        $app = $this->app();
        $deployments = $app->deployments()->limit(30)->get();
        $status = $app->isManaged() ? $this->agent('laravel.status', $app->agentApp(), []) : [];
        $onDisk = collect($status['releases'] ?? [])->pluck('name')->all();
        $busy = $deployments->contains(fn (LaravelDeployment $d) => $d->status->isBusy());

        return view('livewire.laravel.sections.deployments', [
            'app' => $app,
            'deployments' => $deployments,
            // Releases still on disk that are not live can be returned to.
            'canReturn' => fn (LaravelDeployment $d) => $d->status->isLive() && $d->kind !== 'rollback' && $d->release !== null
                && $d->release !== $app->current_release && in_array($d->release, $onDisk, true),
            'log' => $this->logId ? $deployments->firstWhere('id', $this->logId) ?? LaravelDeployment::find($this->logId) : null,
            'rollbackTo' => $this->rollbackId ? $deployments->firstWhere('id', $this->rollbackId) : null,
            'busy' => $busy,
            'webhookShort' => Str::limit($app->webhookUrl(), 60),
            'picker' => $this->connecting ? $this->picker() : null,
            'hosts' => $this->connecting ? $this->gitHosts() : [],
        ]);
    }
}
