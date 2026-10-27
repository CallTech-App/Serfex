<?php

namespace App\Services\Sites;

use App\Enums\GitDeploymentStatus;
use App\Jobs\DeploySiteFromGit;
use App\Models\GitDeployment;
use App\Models\GitRepository;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;

/**
 * Git dağıtımı of sites that are not Laravel applications: deploys of the
 * branch into the site's folder, returns to an earlier commit and the undo
 * of the first deploy run in the background (DeploySiteFromGit); their
 * output is read from the agent as it is written.
 */
class GitDeploys
{
    /** A repository address: https://host/owner/name(.git) or git@host:owner/name.git. */
    public const REPOSITORY_PATTERN = '#^(https://[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/-]+|ssh://[A-Za-z0-9._-]+@[A-Za-z0-9.-]+(:[0-9]+)?/[A-Za-z0-9._~/-]+|[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:[A-Za-z0-9._~/-]+)$#';

    public const BRANCH_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$~';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Deploy the newest commit of the repository's branch.
     */
    public function deploy(GitRepository $repo, ?User $user = null, ?string $triggeredBy = null): GitDeployment
    {
        return $this->start($repo, 'deploy', $user, $triggeredBy, ['branch' => $repo->branch]);
    }

    /**
     * Put the files of an earlier deploy back.
     */
    public function rollback(GitRepository $repo, GitDeployment $to, ?User $user = null): GitDeployment
    {
        return $this->start($repo, 'rollback', $user, null, ['branch' => $to->branch, 'commit' => $to->commit, 'message' => $to->message, 'author' => $to->author]);
    }

    /**
     * Remove the files of the first deploy and bring back what it overwrote.
     */
    public function undoFirst(GitRepository $repo, ?User $user = null): GitDeployment
    {
        return $this->start($repo, 'undo', $user, null, []);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function start(GitRepository $repo, string $kind, ?User $user, ?string $triggeredBy, array $fields): GitDeployment
    {
        $deployment = GitDeployment::create($fields + [
            'git_repository_id' => $repo->id,
            'number' => $repo->nextNumber(),
            'kind' => $kind,
            'status' => GitDeploymentStatus::Queued,
            'triggered_by' => $triggeredBy ?? $user?->name,
            'run' => 'git-'.Str::lower(Str::random(12)),
        ]);
        DeploySiteFromGit::dispatch($deployment);

        return $deployment;
    }

    /**
     * The deploy that first put files into the folder, while its undo is
     * possible: the folder holds a deploy and nothing was undone since.
     * Its undo removes the deployed files and brings back what it
     * overwrote.
     */
    public function firstDeploy(GitRepository $repo): ?GitDeployment
    {
        if ($repo->live_commit === null) {
            return null;
        }

        return $repo->deployments()->where('kind', 'deploy')->whereNull('previous_commit')
            ->whereIn('status', [GitDeploymentStatus::Succeeded, GitDeploymentStatus::CommandsFailed])->first();
    }

    /**
     * The site's deploy key (its public half), made on first use.
     */
    public function key(GitRepository $repo): string
    {
        $key = (string) ($this->agent->call('site.git.key', $repo->target())['public_key'] ?? '');
        $repo->update(['deploy_key' => $key]);

        return $key;
    }

    /**
     * Forget the repository: its clone, key and history go, the deployed
     * files stay in the folder.
     *
     * @throws AgentException
     */
    public function disconnect(GitRepository $repo): void
    {
        $this->agent->call('site.git.remove', $repo->target());
        $repo->delete();
    }

    /**
     * The log of $run from $offset on; null when the run is not $repo's.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    public function read(GitRepository $repo, string $run, int $offset = 0): ?array
    {
        $deployment = $repo->deployments()->where('run', $run)->first();
        if ($deployment === null) {
            return null;
        }
        try {
            $chunk = $this->agent->call('site.git.read', ['domain' => $repo->site->domain, 'run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => false, 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $deployment->status->isBusy(),
        ];
    }
}
