<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\ReadsRunLogs;
use App\Models\LaravelDeployment;
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

    protected const MANAGE_METHODS = ['openConnect', 'connect'];

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

    public string $healthPath = '/up';

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->hooks = $app->hookScripts();
        $this->healthPath = $app->health_path;
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
        $this->dispatch('toast', message: 'Dağıtım ayarı kaydedildi.');
    }

    public function saveHealthPath(): void
    {
        $this->healthPath = '/'.ltrim(trim($this->healthPath), '/');
        $this->validate(['healthPath' => ['required', 'max:200', 'regex:~^/[^\s#]*$~']], [], ['healthPath' => 'sağlık adresi']);
        $this->app()->update(['health_path' => $this->healthPath]);
        $this->dispatch('toast', message: 'Sağlık kontrolü adresi kaydedildi.');
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
            message: 'Dağıtım adımları kaydedildi; bir sonraki dağıtımda çalışır.',
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
            message: "#{$to->number} sürümüne dönülüyor.",
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
        $this->reset('provider', 'repository', 'branch', 'private');
        $this->resetValidation();
        $this->connecting = true;
    }

    /**
     * Attach a Git repository to an application installed as a new project
     * or found on the site, so it can be deployed.
     */
    public function connect(): void
    {
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->validate([
            'provider' => ['required', 'in:'.implode(',', array_keys(LaravelIndex::PROVIDERS))],
            'repository' => ['required', 'max:300', 'regex:'.LaravelApps::REPOSITORY_PATTERN],
            'branch' => ['nullable', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'],
        ], [
            'repository.regex' => 'Depo adresi "kullanıcı/depo", https://… veya git@… biçiminde olmalı.',
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
        $app->update(['repository' => $repo, 'branch' => $this->branch ?: null]);
        $this->connecting = false;
        if ($this->private) {
            $this->showKey();
        }
        $this->dispatch('toast', message: 'Depo bağlandı. "Dağıt" ile ilk sürümü yayına alabilirsiniz.');
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
        ]);
    }
}
