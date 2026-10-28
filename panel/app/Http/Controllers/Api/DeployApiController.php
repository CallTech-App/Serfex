<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\LaravelPreview;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\Previews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The deploy API for CI and the command line: deploy, follow a deploy,
 * go back to an earlier release, and open or remove preview environments.
 * Every route works on the application AuthenticateAppAccess found: the
 * one the token belongs to, or the one named for a person's key.
 */
class DeployApiController extends Controller
{
    /** @var array<int, int|null> app id => live deployment number */
    private array $live = [];

    private const REF = ['nullable', 'string', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~'];

    public function show(Request $request): JsonResponse
    {
        $app = $this->app($request);

        return response()->json([
            'domain' => $app->site->domain,
            'status' => $app->status->value,
            'preview_of' => $app->previewOf?->site?->domain,
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
            return response()->json(['message' => $app->repository === null
                ? __('Bu uygulama bir depoya bağlı değil; dağıtılamaz.')
                : __('Uygulama şu an dağıtılamaz; süren işin bitmesini bekleyin.')], 409);
        }
        if ($app->hasRunningDeployment()) {
            return response()->json(['message' => __('Bir dağıtım zaten sürüyor.')], 409);
        }
        $deployment = $apps->deploy($app, $data['ref'] ?? null, $request->user(), migrate: $data['migrate'] ?? null, frontend: $data['frontend'] ?? null, triggeredBy: $this->actor($request));

        return response()->json(['message' => __('Dağıtım sıraya alındı.')] + $this->deployment($app, $deployment), 202);
    }

    /**
     * GET /api/v1/apps/{domain}/deployments[?limit=<n>]: newest first.
     */
    public function deployments(Request $request): JsonResponse
    {
        $app = $this->app($request);
        $list = $app->deployments()->latest('number')->limit(min(100, max(1, (int) $request->query('limit', 20))))->get();

        return response()->json(['data' => $list->map(fn (LaravelDeployment $d) => $this->deployment($app, $d))->values()]);
    }

    /**
     * POST /api/v1/apps/{domain}/rollback {to?}: puts an earlier release
     * live again: deployment number to, else the one before the current.
     */
    public function rollback(Request $request, LaravelApps $apps, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);
        $to = $request->validate(['to' => ['nullable', 'integer', 'min:1']])['to'] ?? null;
        if ($app->status->isBusy() || $app->hasRunningDeployment()) {
            return response()->json(['message' => __('Uygulama şu an dağıtılamaz; süren işin bitmesini bekleyin.')], 409);
        }
        // As on the page: a live release still on disk that is not live now.
        try {
            $onDisk = collect($agent->call('laravel.status', $app->agentApp(), config('serfex.agent.job_timeout'))['releases'] ?? [])->pluck('name')->all();
        } catch (AgentException $e) {
            return response()->json(['message' => __('Sunucu ajanına ulaşılamadı: :reason', ['reason' => $e->reason])], 503);
        }
        $target = $app->deployments()->latest('number')->get()->first(fn (LaravelDeployment $d) => ($to === null || $d->number === $to)
            && $d->status->isLive() && $d->kind !== 'rollback' && $d->release !== null
            && $d->release !== $app->current_release && in_array($d->release, $onDisk, true));
        if ($target === null) {
            return response()->json(['message' => $to !== null
                ? __(':to numaralı dağıtıma dönülemez: başarılı bir dağıtım değil, yayında ya da sunucuda artık yok.', ['to' => $to])
                : __('Dönülecek önceki bir sürüm yok.')], 409);
        }
        $deployment = $apps->rollback($app, $target, $request->user());
        $deployment->update(['triggered_by' => $this->actor($request)]);

        return response()->json(['message' => __('Geri dönüş sıraya alındı.')] + $this->deployment($app, $deployment), 202);
    }

    public function showDeployment(Request $request, string $domain, int $number): JsonResponse
    {
        $app = $this->app($request);
        $deployment = $app->deployments()->where('number', $number)->first();
        if ($deployment === null) {
            return response()->json(['message' => __(':number numaralı bir dağıtım yok.', ['number' => $number])], 404);
        }

        return response()->json($this->deployment($app, $deployment));
    }

    public function createPreview(Request $request, Previews $previews): JsonResponse
    {
        $app = $this->app($request);
        $data = $request->validate(['branch' => ['required', ...array_slice(self::REF, 1)]]);
        try {
            $preview = $previews->create($app, $data['branch'], $request->user(), source: 'api', by: $this->actor($request));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['message' => __('Önizleme ortamı sıraya alındı.'), 'preview' => $preview->name, 'url' => 'https://'.$preview->domain()], 202);
    }

    public function deletePreview(Request $request, string $domain, string $name, Previews $previews): JsonResponse
    {
        $preview = $this->app($request)->previews()->where('name', $name)->first();
        if (! $preview instanceof LaravelPreview) {
            return response()->json(['message' => __(':name adında bir önizleme ortamı yok.', ['name' => $name])], 404);
        }
        $previews->delete($preview);

        return response()->json(['message' => __('Önizleme ortamı kaldırılıyor.')], 202);
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
            'triggered_by' => $deployment->triggered_by,
            'run' => $deployment->run,
            'live' => $deployment->number === $this->liveNumber($app),
            'done' => ! $deployment->status->isBusy(),
            'url' => route('laravel.show', ['app' => $app->id, 'section' => 'deployments']),
        ];
    }

    /**
     * The newest deployment that put the current release live (a rollback
     * shares its release with the deployment it went back to).
     */
    private function liveNumber(LaravelApp $app): ?int
    {
        if ($app->current_release === null) {
            return null;
        }

        return $this->live[$app->id] ??= $app->deployments()
            ->where('release', $app->current_release)
            ->where('status', DeploymentStatus::Succeeded)
            ->max('number');
    }

    private function app(Request $request): LaravelApp
    {
        return $request->attributes->get('laravel_app')->loadMissing('site', 'latestDeployment');
    }

    /**
     * Who the deploy history names: "API: <token>" or the person.
     */
    private function actor(Request $request): string
    {
        return (string) $request->attributes->get('actor');
    }
}
