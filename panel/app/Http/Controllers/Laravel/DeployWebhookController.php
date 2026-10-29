<?php

namespace App\Http\Controllers\Laravel;

use App\Http\Controllers\Controller;
use App\Models\GitRepository;
use App\Models\LaravelApp;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Previews;
use App\Services\Sites\GitDeploys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Deploys a Laravel application when its repository says so: a push
 * webhook from GitHub, GitLab or Bitbucket, or a plain POST from CI. The
 * secret token in the address identifies the application.
 *
 * Pull request (merge request) events open, update and remove preview
 * environments when they are on. Pull requests from forks are ignored:
 * their code is not the repository owner's.
 *
 * The same address deploys sites set up for Git dağıtımı (GitRepository);
 * the token says which.
 *
 * The token is also the webhook's secret: GitHub and Bitbucket sign the
 * body with it, GitLab sends it back. A wrong signature is refused, and
 * pull request events count only when signed, because anyone who learnt
 * the address could otherwise make previews of any branch.
 */
class DeployWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, LaravelApps $apps, Previews $previews, GitDeploys $sites): JsonResponse
    {
        $app = strlen($token) < 32 ? null : LaravelApp::with('site')->where('webhook_token', $token)->first();
        $repo = $app !== null || strlen($token) < 32 ? null : GitRepository::with('site')->where('webhook_token', $token)->first();
        if ($app === null && $repo === null) {
            return response()->json(['message' => 'Unknown deploy hook.'], 404);
        }
        $signed = $this->signed($request, $token);
        if ($signed === false) {
            return response()->json(['message' => 'The webhook signature does not match; its secret must be the token in this address.'], 403);
        }
        if ($repo !== null) {
            return $this->site($request, $repo, $sites);
        }
        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }
        $pr = $this->pullRequest($request);
        if ($pr !== null && $signed !== true) {
            return response()->json(['message' => 'Pull request events are used only when signed: set the webhook secret to the token in this address.'], 202);
        }
        if ($pr !== null) {
            return $this->preview($app, $pr, $previews);
        }

        $pushed = $this->pushedBranch($request);
        // A push webhook deploys only when automatic deploys are on, and
        // only for the application's branch; a plain POST (from CI) always
        // deploys, the branch given as ?ref= or the default one.
        if ($pushed !== null) {
            if (! $app->auto_deploy) {
                return response()->json(['message' => 'Automatic deploys are off for this application.'], 202);
            }
            if ($app->branch !== null && $pushed !== $app->branch) {
                return response()->json(['message' => "Push to {$pushed} ignored; the application deploys {$app->branch}."], 202);
            }
        }
        if ($app->repository === null || $app->status->isBusy()) {
            return response()->json(['message' => 'This application cannot be deployed now.'], 409);
        }
        if ($app->hasRunningDeployment()) {
            return response()->json(['message' => 'A deploy is already running.'], 409);
        }

        $ref = $pushed ?? (is_string($request->query('ref')) && $request->query('ref') !== '' ? $request->query('ref') : null);
        $deployment = $apps->deploy($app, $ref, triggeredBy: $this->trigger($request));

        return response()->json(['message' => 'Deploy queued.', 'deployment' => $deployment->number], 202);
    }

    /**
     * A push to the site's branch (when automatic deploys are on) or a
     * plain POST deploys the branch into the site's folder.
     */
    private function site(Request $request, GitRepository $repo, GitDeploys $sites): JsonResponse
    {
        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
        }
        if ($this->pullRequest($request) !== null) {
            return response()->json(['message' => 'Nothing to do for pull request events.'], 202);
        }
        $pushed = $this->pushedBranch($request);
        if ($pushed !== null) {
            if (! $repo->auto_deploy) {
                return response()->json(['message' => 'Automatic deploys are off for this site.'], 202);
            }
            if ($pushed !== $repo->branch) {
                return response()->json(['message' => "Push to {$pushed} ignored; the site deploys {$repo->branch}."], 202);
            }
        }
        if ($repo->site->status->isBusy() || $repo->site->isSuspended()) {
            return response()->json(['message' => 'This site cannot be deployed now.'], 409);
        }
        if ($repo->hasRunningDeployment()) {
            return response()->json(['message' => 'A deploy is already running.'], 409);
        }
        $deployment = $sites->deploy($repo, triggeredBy: $this->trigger($request));

        return response()->json(['message' => 'Deploy queued.', 'deployment' => $deployment->number], 202);
    }

    /**
     * @param  array{provider: string, action: string, number: int, title: string, url: ?string, branch: string, fork: bool}  $pr
     */
    private function preview(LaravelApp $app, array $pr, Previews $previews): JsonResponse
    {
        if ($pr['action'] === 'ignore') {
            return response()->json(['message' => 'Nothing to do for this pull request event.'], 202);
        }
        if (! $app->previews_enabled) {
            return response()->json(['message' => 'Preview environments are off for this application.'], 202);
        }
        if ($pr['fork']) {
            return response()->json(['message' => 'Pull requests from forks get no preview environment.'], 202);
        }
        $preview = $app->previews()->where('pr_number', $pr['number'])->first();
        if ($pr['action'] === 'close') {
            if ($preview === null) {
                return response()->json(['message' => 'No preview environment for this pull request.'], 202);
            }
            $previews->delete($preview);

            return response()->json(['message' => 'Preview environment removed.', 'preview' => $preview->name], 202);
        }
        if ($pr['action'] === 'update' && $preview === null) {
            $pr['action'] = 'open';
        }
        try {
            $preview = $previews->create($app, $pr['branch'], pr: $pr['number'], title: $pr['title'], url: $pr['url'], source: $pr['provider']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Preview environment queued.', 'preview' => $preview->name, 'url' => 'https://'.$preview->domain()], 202);
    }

    /**
     * True when the request proves it knows $secret (GitHub's and
     * Bitbucket's HMAC of the body, GitLab's token), false when it tries
     * and fails, null when it carries no proof (a plain POST from CI, or
     * a webhook added by hand without a secret).
     */
    private function signed(Request $request, string $secret): ?bool
    {
        $signature = $request->header('X-Hub-Signature-256') ?? $request->header('X-Hub-Signature');
        if (is_string($signature) && $signature !== '') {
            // Old GitHub servers send only the SHA-1 form.
            $algo = str_starts_with($signature, 'sha1=') ? 'sha1' : 'sha256';

            return hash_equals($algo.'='.hash_hmac($algo, $request->getContent(), $secret), $signature);
        }
        $token = $request->header('X-Gitlab-Token');
        if (is_string($token) && $token !== '') {
            return hash_equals($secret, $token);
        }

        return null;
    }

    /**
     * The pull request (merge request) event in the request, if it is one:
     * action open, update or close.
     *
     * @return array{provider: string, action: string, number: int, title: string, url: ?string, branch: string, fork: bool}|null
     */
    private function pullRequest(Request $request): ?array
    {
        $event = null;
        if ($request->header('X-GitHub-Event') === 'pull_request') {
            $action = match ((string) $request->json('action')) {
                'opened', 'reopened', 'ready_for_review' => 'open',
                'synchronize', 'edited' => 'update',
                'closed' => 'close',
                default => null,
            };
            $event = ['provider' => 'github', 'action' => $action, 'number' => $request->json('pull_request.number'), 'title' => $request->json('pull_request.title'),
                'url' => $request->json('pull_request.html_url'), 'branch' => $request->json('pull_request.head.ref'),
                'fork' => $request->json('pull_request.head.repo.full_name') !== $request->json('pull_request.base.repo.full_name')];
        } elseif ($request->header('X-Gitlab-Event') === 'Merge Request Hook') {
            $action = match ((string) $request->json('object_attributes.action')) {
                'open', 'reopen' => 'open',
                'update' => 'update',
                'close', 'merge' => 'close',
                default => null,
            };
            $event = ['provider' => 'gitlab', 'action' => $action, 'number' => $request->json('object_attributes.iid'), 'title' => $request->json('object_attributes.title'),
                'url' => $request->json('object_attributes.url'), 'branch' => $request->json('object_attributes.source_branch'),
                'fork' => $request->json('object_attributes.source_project_id') !== $request->json('object_attributes.target_project_id')];
        } elseif (str_starts_with((string) $request->header('X-Event-Key'), 'pullrequest:')) {
            $action = match (substr((string) $request->header('X-Event-Key'), 12)) {
                'created' => 'open',
                'updated' => 'update',
                'fulfilled', 'rejected' => 'close',
                default => null,
            };
            $event = ['provider' => 'bitbucket', 'action' => $action, 'number' => $request->json('pullrequest.id'), 'title' => $request->json('pullrequest.title'),
                'url' => $request->json('pullrequest.links.html.href'), 'branch' => $request->json('pullrequest.source.branch.name'),
                'fork' => $request->json('pullrequest.source.repository.full_name') !== $request->json('pullrequest.destination.repository.full_name')];
        }
        if ($event === null) {
            return null;
        }
        if ($event['action'] === null || ! is_int($event['number']) || ! is_string($event['branch'])
            || ! preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$~', $event['branch']) || str_contains($event['branch'], '..')) {
            // An event about a pull request that needs nothing from us.
            return ['provider' => $event['provider'], 'action' => 'ignore', 'number' => 0, 'title' => '', 'url' => null, 'branch' => '', 'fork' => false];
        }
        $event['title'] = is_string($event['title']) ? $event['title'] : '';
        $event['url'] = is_string($event['url']) && str_starts_with($event['url'], 'https://') ? $event['url'] : null;

        return $event;
    }

    /**
     * The branch a push webhook is about, or null for a plain request.
     */
    private function pushedBranch(Request $request): ?string
    {
        // GitHub and GitLab: "ref": "refs/heads/main".
        $ref = $request->json('ref');
        if (is_string($ref) && str_starts_with($ref, 'refs/heads/')) {
            return substr($ref, 11);
        }
        // Bitbucket: push.changes[].new.name.
        $name = $request->json('push.changes.0.new.name');

        return is_string($name) ? $name : null;
    }

    private function trigger(Request $request): string
    {
        return match (true) {
            $request->hasHeader('X-GitHub-Event') => 'GitHub webhook',
            $request->hasHeader('X-Gitlab-Event') => 'GitLab webhook',
            $request->hasHeader('X-Event-Key') => 'Bitbucket webhook',
            default => 'Webhook',
        };
    }
}
