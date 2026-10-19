<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaravelApiToken;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\LaravelPreview;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Previews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The deploy API for CI and the command line: deploy, follow a deploy,
 * and open or remove preview environments. Every route works on the one
 * application the bearer token belongs to.
 */
class DeployApiController extends Controller
{
    private const REF = ['nullable', 'string', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'];

    public function show(Request $request): JsonResponse
    {
        $app = $this->app($request);

        return response()->json([
            'domain' => $app->site->domain,
            'status' => $app->status->value,
            'repository' => $app->repositoryName(),
            'branch' => $app->branch,
            'release' => $app->current_release,
            'laravel' => $app->laravel_version,
            'php' => $app->site->php_version,
            'latest_deployment' => $app->latestDeployment ? $this->deployment($app, $app->latestDeployment) : null,
        ]);
    }

    public function deploy(Request $request, LaravelApps $apps): JsonResponse
    {
        $app = $this->app($request);
        $data = $request->validate([
            'ref' => self::REF,
            'migrate' => ['nullable', 'boolean'],
            'frontend' => ['nullable', 'boolean'],
        ]);
        if ($app->repository === null || $app->status->isBusy()) {
            return response()->json(['message' => 'This application cannot be deployed now.'], 409);
        }
        if ($app->hasRunningDeployment()) {
            return response()->json(['message' => 'A deploy is already running.'], 409);
        }
        $deployment = $apps->deploy($app, $data['ref'] ?? null, migrate: $data['migrate'] ?? null, frontend: $data['frontend'] ?? null, triggeredBy: 'API: '.$this->token($request)->name);

        return response()->json(['message' => 'Deploy queued.'] + $this->deployment($app, $deployment), 202);
    }

    public function showDeployment(Request $request, string $domain, int $number): JsonResponse
    {
        $app = $this->app($request);
        $deployment = $app->deployments()->where('number', $number)->first();
        if ($deployment === null) {
            return response()->json(['message' => 'No such deployment.'], 404);
        }

        return response()->json($this->deployment($app, $deployment));
    }

    public function createPreview(Request $request, Previews $previews): JsonResponse
    {
        $app = $this->app($request);
        $data = $request->validate(['branch' => ['required', ...array_slice(self::REF, 1)]]);
        try {
            $preview = $previews->create($app, $data['branch'], source: 'api', by: 'API: '.$this->token($request)->name);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => 'Preview environment queued.', 'preview' => $preview->name, 'url' => 'https://'.$preview->domain()], 202);
    }

    public function deletePreview(Request $request, string $domain, string $name, Previews $previews): JsonResponse
    {
        $preview = $this->app($request)->previews()->where('name', $name)->first();
        if (! $preview instanceof LaravelPreview) {
            return response()->json(['message' => 'No such preview environment.'], 404);
        }
        $previews->delete($preview);

        return response()->json(['message' => 'Preview environment removed.'], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function deployment(LaravelApp $app, LaravelDeployment $deployment): array
    {
        return [
            'deployment' => $deployment->number,
            'kind' => $deployment->kind,
            'status' => $deployment->status->value,
            'ref' => $deployment->ref,
            'commit' => $deployment->commit,
            'message' => $deployment->message,
            'error' => $deployment->error,
            'started_at' => $deployment->started_at?->toIso8601String(),
            'finished_at' => $deployment->finished_at?->toIso8601String(),
            'done' => ! $deployment->status->isBusy(),
            'url' => route('laravel.show', ['app' => $app->id, 'bolum' => 'dagitimlar']),
        ];
    }

    private function app(Request $request): LaravelApp
    {
        return $request->attributes->get('laravel_app')->loadMissing('site', 'latestDeployment');
    }

    private function token(Request $request): LaravelApiToken
    {
        return $request->attributes->get('api_token');
    }
}
