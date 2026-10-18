<?php

namespace App\Http\Controllers\Laravel;

use App\Http\Controllers\Controller;
use App\Models\LaravelApp;
use App\Services\Laravel\LaravelApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Deploys a Laravel application when its repository says so: a push
 * webhook from GitHub, GitLab or Bitbucket, or a plain POST from CI. The
 * secret token in the address identifies the application.
 */
class DeployWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, LaravelApps $apps): JsonResponse
    {
        $app = LaravelApp::with('site')->where('webhook_token', $token)->first();
        if ($app === null || strlen($token) < 32) {
            return response()->json(['message' => 'Unknown deploy hook.'], 404);
        }
        if ($request->header('X-GitHub-Event') === 'ping') {
            return response()->json(['message' => 'pong']);
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
