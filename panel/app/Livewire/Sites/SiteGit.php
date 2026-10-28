<?php

namespace App\Livewire\Sites;

use App\Enums\GitDeploymentStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Concerns\PicksGitRepository;
use App\Models\GitDeployment;
use App\Models\GitRepository;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviders;
use App\Services\Sites\GitDeploys;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Git dağıtımı: a site's folder (public_html unless chosen otherwise)
 * comes from a Git repository. "Dağıt" checks the newest commit of the
 * branch out into the folder; a webhook can do it on every push. Files the
 * repository does not track stay. Every deploy can be returned to, and
 * the first one undone, which brings back the files it overwrote.
 *
 * With a connected GitHub, GitLab or Bitbucket account the repository is
 * picked from a list; Serfex then adds the deploy key and the webhook to
 * it, and removes both when the repository is disconnected.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteGit extends Component
{
    use PicksGitRepository;

    public int $siteId;

    /** github, gitlab or bitbucket to pick from the account's list; '' to type the address. */
    public string $gitSource = '';

    /** What the host refused while linking the picked repository. */
    public array $linkErrors = [];

    public string $repository = '';

    public string $branch = 'main';

    public string $path = 'public_html';

    public string $token = '';

    public bool $clearToken = false;

    public string $commands = '';

    public bool $editing = false;

    public bool $showingKey = false;

    public ?string $publicKey = null;

    public ?int $logId = null;

    public ?int $rollbackId = null;

    public bool $undoing = false;

    public bool $disconnecting = false;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
        $hosts = $this->gitHosts();
        $asked = (string) request()->query('source', '');
        $this->gitSource = match (true) {
            isset($hosts[$asked]) => $asked,
            default => collect(array_keys($hosts))->first(fn ($p) => $this->gitAccount($p) !== null) ?? array_key_first($hosts) ?? '',
        };
    }

    protected function pickingFrom(): ?string
    {
        return $this->gitSource !== '' && isset($this->gitHosts()[$this->gitSource]) ? $this->gitSource : null;
    }

    protected function pickerReturnPath(string $provider): string
    {
        return route('sites.git', [$this->siteId, 'source' => $provider], false);
    }

    public function updatedGitSource(): void
    {
        $this->resetPicker();
        $this->resetValidation();
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser', 'laravelApp', 'gitRepository')->findOrFail($this->siteId);
    }

    private function repo(): ?GitRepository
    {
        return GitRepository::with('site.systemUser')->where('site_id', $this->siteId)->first();
    }

    private function deployment(int $id): ?GitDeployment
    {
        $repo = $this->repo();

        return $repo ? GitDeployment::where('git_repository_id', $repo->id)->find($id) : null;
    }

    /**
     * Connect the repository from the form; an SSH address shows the
     * deploy key to add to it.
     */
    public function connect(GitDeploys $deploys, GitAccounts $accounts, GitProviders $providers): void
    {
        $site = $this->site();
        if ($site->laravelApp !== null || $site->gitRepository !== null) {
            return;
        }
        $picked = null;
        if ($this->pickingFrom() !== null) {
            $picked = $this->pickedRepository();
            if ($picked === null) {
                return;
            }
            $this->repository = $picked['repo']['ssh_url'];
            $this->token = '';
        }
        $this->validateForm();
        $repo = GitRepository::create([
            'site_id' => $site->id,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'path' => $this->path,
            'token' => $this->token !== '' && str_starts_with($this->repository, 'https://') ? $this->token : null,
            'commands' => $this->commands !== '' ? $this->commands : null,
        ]);
        $this->token = '';
        $this->linkErrors = [];
        if ($picked !== null) {
            try {
                $key = $deploys->key($repo);
            } catch (AgentException $e) {
                // Nothing was added to the repository yet: start over.
                $repo->delete();
                $this->dispatch('toast', message: __('Dağıtım anahtarı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

                return;
            }
            $linked = $accounts->link($repo, $picked['account'], $picked['repo']['name'], $key, $providers->webhookUrl($repo->webhook_token), pullRequests: false);
            $this->linkErrors = $linked['errors'];
            $this->resetPicker();
            if (! $linked['key']) {
                $this->publicKey = $key;
                $this->showingKey = true;
            }
            $label = GitProviders::LABELS[$picked['account']->provider];
            $this->dispatch('toast', message: match (true) {
                $linked['key'] && $linked['hook'] => __('Depo bağlandı; dağıtım anahtarı ve webhook :provider\'a eklendi. "Dağıt" ile dosyaları :path klasörüne alabilirsiniz.', ['provider' => $label, 'path' => $repo->path]),
                $linked['key'] => __('Depo bağlandı ve dağıtım anahtarı :provider\'a eklendi; webhook eklenemedi.', ['provider' => $label]),
                default => __('Depo bağlandı; dağıtım anahtarını depoya elle ekleyin.'),
            });

            return;
        }
        if ($repo->usesKey()) {
            $this->showKey($deploys);
        }
        $this->dispatch('toast', message: __('Depo bağlandı. "Dağıt" ile dosyaları :path klasörüne alabilirsiniz.', ['path' => $repo->path]));
    }

    private function validateForm(): void
    {
        $this->repository = trim($this->repository);
        $this->branch = trim($this->branch);
        $this->path = trim(trim($this->path), '/');
        $this->token = trim($this->token);
        $this->commands = trim(str_replace("\r\n", "\n", $this->commands));
        $this->validate([
            'repository' => ['required', 'max:300', 'regex:'.GitDeploys::REPOSITORY_PATTERN, 'not_regex:~\.\.~'],
            'branch' => ['required', 'max:200', 'regex:'.GitDeploys::BRANCH_PATTERN, 'not_regex:~\.\.~'],
            'path' => ['required', 'max:200', 'regex:~^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$~', 'not_regex:~(^|/)\.\.?(/|$)|^laravel(/|$)~'],
            'token' => ['nullable', 'max:500', 'regex:~^\S*$~'],
            'commands' => ['nullable', 'max:16000'],
        ], [
            'repository.regex' => __('Depo adresi https://… veya git@… biçiminde olmalı.'),
            'path.regex' => __('Site klasörünün içinde bir klasör yazın, örneğin public_html.'),
            'path.not_regex' => __('Site klasörünün içinde bir klasör yazın, örneğin public_html.'),
        ], ['repository' => __('depo'), 'branch' => __('dal'), 'path' => __('hedef klasör'), 'token' => __('erişim anahtarı'), 'commands' => __('komutlar')]);
    }

    public function openSettings(): void
    {
        $repo = $this->repo();
        if ($repo === null) {
            return;
        }
        $this->resetValidation();
        $this->repository = $repo->repository;
        $this->branch = $repo->branch;
        $this->path = $repo->path;
        $this->commands = (string) $repo->commands;
        $this->token = '';
        $this->clearToken = false;
        $this->editing = true;
    }

    public function saveSettings(GitAccounts $accounts): void
    {
        $repo = $this->repo();
        if ($repo === null) {
            return;
        }
        $this->validateForm();
        if ($repo->remote_repository !== null && $this->repository !== $repo->repository) {
            // Another repository: the key and webhook added to the old one go.
            $accounts->unlink($repo);
        }
        $before = $repo->only(['repository', 'branch', 'path', 'commands']);
        $token = match (true) {
            ! str_starts_with($this->repository, 'https://'), $this->clearToken => null,
            $this->token !== '' => $this->token,
            default => $repo->token,
        };
        $repo->update([
            'repository' => $this->repository,
            'branch' => $this->branch,
            'path' => $this->path,
            'commands' => $this->commands !== '' ? $this->commands : null,
            'token' => $token,
        ]);
        $this->editing = false;
        $this->token = '';
        $this->dispatch('toast',
            message: __('Git dağıtımı ayarları kaydedildi; bir sonraki dağıtımda geçerli olur.'),
            source: $this->getId(),
            undo: ['method' => 'restoreSettings', 'args' => [$before]],
        );
    }

    /**
     * Undo for saved settings (the access token stays as it is now).
     *
     * @param  array<string, string|null>  $settings
     */
    public function restoreSettings(array $settings): void
    {
        $this->repo()?->update(array_intersect_key($settings, array_flip(['repository', 'branch', 'path', 'commands'])));
    }

    public function setAutoDeploy(bool $on): void
    {
        $this->repo()?->update(['auto_deploy' => $on]);
        $this->dispatch('toast', message: $on ? __('Otomatik dağıtım açıldı.') : __('Otomatik dağıtım kapatıldı.'));
    }

    public function deploy(GitDeploys $deploys): void
    {
        $repo = $this->repo();
        if ($repo === null || $repo->hasRunningDeployment() || $repo->site->isSuspended()) {
            return;
        }
        $deployment = $deploys->deploy($repo, auth()->user());
        $this->logId = $deployment->id;
        $this->dispatch('toast', message: __('Dağıtım #:number başladı: :branch dalı → :path.', ['number' => $deployment->number, 'branch' => $repo->branch, 'path' => $repo->path]));
    }

    public function showLog(int $id): void
    {
        $this->logId = $this->deployment($id)?->id;
    }

    public function closeLog(): void
    {
        $this->logId = null;
    }

    public function confirmRollback(int $id): void
    {
        $this->rollbackId = $this->deployment($id)?->id;
    }

    public function rollback(GitDeploys $deploys): void
    {
        $repo = $this->repo();
        $to = $this->rollbackId ? $this->deployment($this->rollbackId) : null;
        $this->rollbackId = null;
        if ($repo === null || $to === null || $to->commit === null || $repo->hasRunningDeployment()) {
            return;
        }
        $live = $repo->deployments()->where('commit', $repo->live_commit)->whereIn('status', [GitDeploymentStatus::Succeeded, GitDeploymentStatus::CommandsFailed])->first();
        $deployment = $deploys->rollback($repo, $to, auth()->user());
        $this->logId = $deployment->id;
        $this->dispatch('toast',
            message: __('#:number sürümüne dönülüyor.', ['number' => $to->number]),
            source: $this->getId(),
            undo: $live ? ['method' => 'undoRollback', 'args' => [$deployment->id, $live->id]] : null,
        );
    }

    /**
     * Undo for a return to an earlier deploy: back to the one that was
     * live before it.
     */
    public function undoRollback(int $rollbackId, int $previousId, GitDeploys $deploys): void
    {
        $repo = $this->repo();
        $previous = $this->deployment($previousId);
        if ($repo === null || $previous === null) {
            return;
        }
        $rollback = $this->deployment($rollbackId);
        if ($rollback?->status === GitDeploymentStatus::Queued) {
            // Not started yet: it simply does not run.
            $rollback->delete();

            return;
        }
        $deploys->rollback($repo, $previous, auth()->user());
    }

    public function undoFirst(GitDeploys $deploys): void
    {
        $this->undoing = false;
        $repo = $this->repo();
        if ($repo === null || $repo->hasRunningDeployment() || $deploys->firstDeploy($repo) === null) {
            return;
        }
        $deployment = $deploys->undoFirst($repo, auth()->user());
        $this->logId = $deployment->id;
        $this->dispatch('toast', message: __('İlk dağıtım geri alınıyor: dağıtılan dosyalar kaldırılıp önceki dosyalar geri yükleniyor.'));
    }

    public function showKey(GitDeploys $deploys): void
    {
        $repo = $this->repo();
        if ($repo === null) {
            return;
        }
        try {
            $this->publicKey = $deploys->key($repo);
            $this->showingKey = true;
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Dağıtım anahtarı oluşturulamadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));
        }
    }

    public function disconnect(GitDeploys $deploys, GitAccounts $accounts): void
    {
        $this->disconnecting = false;
        $repo = $this->repo();
        if ($repo === null || $repo->hasRunningDeployment()) {
            return;
        }
        $accounts->unlink($repo);
        try {
            $deploys->disconnect($repo);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Sunucu depoyu kaldıramadı: :reason', ['reason' => $e->reason ?: $e->getMessage()]));

            return;
        }
        $this->reset('repository', 'branch', 'path', 'token', 'commands', 'logId', 'rollbackId', 'publicKey', 'linkErrors');
        $this->dispatch('toast', message: __('Depo bağlantısı kaldırıldı; dosyalar :path klasöründe duruyor.', ['path' => $repo->path]));
    }

    /**
     * The log of a deploy of this site from $offset on, for <x-run-log>.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $repo = $this->repo();

        return $repo === null ? null : app(GitDeploys::class)->read($repo, $run, $offset);
    }

    public function render(GitDeploys $deploys): View
    {
        $site = $this->site();
        $repo = $site->gitRepository?->setRelation('site', $site);
        $deployments = $repo ? $repo->deployments()->limit(30)->get() : collect();
        $live = $repo?->live_commit;

        return view('livewire.sites.git', [
            'site' => $site,
            'repo' => $repo,
            'deployments' => $deployments,
            'busy' => $deployments->contains(fn (GitDeployment $d) => $d->status->isBusy()),
            'liveDeployment' => $live ? $deployments->first(fn (GitDeployment $d) => $d->commit === $live && $d->status->isLive()) : null,
            'firstDeploy' => $repo ? $deploys->firstDeploy($repo) : null,
            'log' => $this->logId ? $deployments->firstWhere('id', $this->logId) : null,
            'rollbackTo' => $this->rollbackId ? $deployments->firstWhere('id', $this->rollbackId) : null,
            'hosts' => $this->gitHosts(),
            'picker' => $repo === null ? $this->picker() : null,
        ])->title(__('Git dağıtımı · :domain · Serfex', ['domain' => $site->domain]));
    }
}
