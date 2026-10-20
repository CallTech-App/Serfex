<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Livewire\Laravel\Sections\Maintenance as MaintenanceSection;
use App\Models\LaravelApp;
use App\Models\LaravelCommand;
use App\Models\LaravelWorker;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Audit\AuditLog;
use App\Services\Laravel\CommandLine;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\Environments;
use App\Services\Laravel\LaravelApps;
use App\Services\Laravel\LogParser;
use App\Services\Laravel\Maintenance;
use App\Services\Laravel\RunLogs;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The rest of a Laravel application's API, next to deploys: .env,
 * Artisan, Composer and Node.js commands, queues, maintenance mode and
 * the application's logs. What each route needs (view or operate, and the
 * shell feature for commands) is checked by AuthenticateAppAccess.
 */
class AppApiController extends Controller
{
    /**
     * GET /api/v1/apps: the applications the key's person may see.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $apps = LaravelApp::with('site.systemUser', 'latestDeployment')->get()
            ->filter(fn (LaravelApp $app) => $app->site !== null && $user->laravelRole($app) !== null)
            ->sortBy(fn (LaravelApp $app) => $app->site->domain)
            ->values();

        return response()->json(['data' => $apps->map(fn (LaravelApp $app) => [
            'domain' => $app->site->domain,
            'status' => $app->status->value,
            'role' => $user->laravelRole($app),
            'preview_of' => $app->preview_of_id !== null ? LaravelApp::with('site')->find($app->preview_of_id)?->site?->domain : null,
            'repository' => $app->repositoryName(),
            'branch' => $app->branch,
            'release' => $app->current_release,
            'last_deploy' => $app->latestDeployment ? [
                'number' => $app->latestDeployment->number,
                'status' => $app->latestDeployment->status->value,
                'finished_at' => $app->latestDeployment->finished_at?->toIso8601String(),
            ] : null,
        ])]);
    }

    /**
     * GET /api/v1/apps/{domain}/runs/{run}?offset=<bytes>: the output of a
     * deploy or command from offset on.
     */
    public function run(Request $request, RunLogs $logs, string $domain, string $run): JsonResponse
    {
        $chunk = $logs->read($this->app($request), $run, (int) $request->query('offset', 0));
        if ($chunk === null) {
            return $this->refuse(__('Bu uygulamada böyle bir çalıştırma yok.'), 404);
        }

        return response()->json(['data' => $chunk]);
    }

    /**
     * GET /api/v1/apps/{domain}/env: KEY => value, and the file itself.
     */
    public function env(Request $request, Environments $environments): JsonResponse
    {
        $files = $this->agentDo(fn () => $environments->read($this->app($request)));

        return response()->json(['data' => [
            'exists' => $files['env'] !== null,
            'values' => (object) EnvFile::values((string) $files['env']),
            'content' => (string) $files['env'],
            'missing' => EnvFile::compare((string) $files['env'], $files['example'])['missing'],
        ]]);
    }

    /**
     * PUT /api/v1/apps/{domain}/env {content}: the whole file.
     */
    public function replaceEnv(Request $request, Environments $environments): JsonResponse
    {
        $content = str_replace("\r\n", "\n", (string) $request->validate(['content' => ['present', 'nullable', 'string', 'max:262144']])['content']);

        return $this->saveEnv($request, $environments, fn () => $content);
    }

    /**
     * PATCH /api/v1/apps/{domain}/env {set: {KEY: value}, unset: [KEY]}:
     * changes keys in place and leaves the rest of the file as it is.
     */
    public function changeEnv(Request $request, Environments $environments): JsonResponse
    {
        $data = $request->validate([
            'set' => ['nullable', 'array'],
            'set.*' => ['nullable', 'string', 'max:65535'],
            'unset' => ['nullable', 'array'],
            'unset.*' => ['string'],
        ]);
        $set = $data['set'] ?? [];
        $unset = array_map('strtoupper', $data['unset'] ?? []);
        foreach ([...array_keys($set), ...$unset] as $key) {
            if (! EnvFile::isValidKey(strtoupper((string) $key))) {
                return $this->refuse(__(':key geçerli bir anahtar değil: büyük harf, rakam ve _ içerebilir.', ['key' => $key]));
            }
        }

        return $this->saveEnv($request, $environments, function (string $before) use ($set, $unset) {
            $values = EnvFile::values($before);
            foreach ($set as $key => $value) {
                $values[strtoupper((string) $key)] = (string) $value;
            }
            foreach ($unset as $key) {
                unset($values[$key]);
            }

            return EnvFile::apply($before, $values);
        });
    }

    /**
     * @param  callable(string): string  $change  the new file from the current one
     */
    private function saveEnv(Request $request, Environments $environments, callable $change): JsonResponse
    {
        $app = $this->app($request);
        $before = (string) $this->agentDo(fn () => $environments->read($app))['env'];
        $content = $change($before);
        if ($content === $before) {
            return response()->json(['message' => __('Değişiklik yok.'), 'data' => ['changed' => false, 'summary' => null]]);
        }
        $result = $this->agentDo(fn () => $environments->save($app, $before, $content, $this->actor($request)), __('.env kaydedilemedi: :value'));
        $this->audit($request, $app, __('Ortam'), $result['version']->summary !== '' ? __('.env kaydedildi: :summary', ['summary' => $result['version']->summary]) : __('.env kaydedildi.'));

        return response()->json([
            'message' => $result['cached'] ? __('.env kaydedildi, config:cache yenilendi.') : __('.env kaydedildi.'),
            'data' => ['changed' => true, 'summary' => $result['version']->summary],
        ]);
    }

    /**
     * POST /api/v1/apps/{domain}/commands {tool, command}: runs an Artisan,
     * Composer or Node.js command in the background, as on the Komutlar
     * page; follow it with GET commands/{id} and runs/{run}.
     */
    public function runCommand(Request $request, LaravelApps $apps): JsonResponse
    {
        $app = $this->app($request);
        $data = $request->validate([
            'tool' => ['required', Rule::in(CommandLine::TOOLS)],
            'command' => ['required', 'string', 'max:4000'],
        ], ['command.required' => __('Bir komut yazın.')]);
        try {
            CommandLine::split($data['command']);
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }
        if ($app->commands()->where('tool', '!=', 'tinker')->whereIn('status', ['queued', 'running'])->count() >= 3) {
            return $this->refuse(__('Aynı anda en fazla 3 komut çalışabilir; birinin bitmesini bekleyin.'), 409);
        }
        $command = $apps->runCommand($app, $data['tool'], trim($data['command']), $request->user());
        $this->audit($request, $app, __('Komutlar'), __('Komut çalıştırıldı: :command', ['command' => CommandLine::prefix($command->tool).' '.$command->command]));

        return response()->json(['data' => $this->command($command)], 202);
    }

    /**
     * GET /api/v1/apps/{domain}/commands/{id}
     */
    public function showCommand(Request $request, string $domain, int $id): JsonResponse
    {
        $command = $this->app($request)->commands()->where('tool', '!=', 'tinker')->find($id);
        if ($command === null) {
            return $this->refuse(__('Böyle bir komut yok.'), 404);
        }

        return response()->json(['data' => $this->command($command)]);
    }

    /**
     * GET /api/v1/apps/{domain}/queues: the workers with their processes
     * and the jobs waiting in each queue.
     */
    public function queues(Request $request, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);
        $workers = $app->workers()->get();
        $status = $this->agentDo(fn () => $agent->call('laravel.status', $app->agentApp(), config('servik.agent.job_timeout')));
        $sizes = $workers->isEmpty() ? [] : $this->agentDo(fn () => $agent->call('laravel.probe', $app->agentApp() + [
            'kind' => 'queues',
            'queues' => $workers->flatMap(fn ($w) => collect($w->queues())->map(fn ($q) => [(string) $w->connection, $q]))->unique()->values()->all(),
        ], config('servik.agent.job_timeout')));
        $states = collect($status['workers'] ?? [])->groupBy('worker');

        return response()->json(['data' => [
            'syncing' => $app->processes_state === 'syncing',
            'error' => $app->processes_error,
            'failed' => $sizes['failed'] ?? null,
            'workers' => $workers->map(fn (LaravelWorker $w) => [
                'name' => $w->name,
                'connection' => $w->connection,
                'queues' => $w->queues(),
                'processes' => $w->processes,
                'running' => $states->get($w->name, collect())->where('state', 'active')->count(),
                'timeout' => $w->timeout,
                'tries' => $w->tries,
                'memory' => $w->memory,
            ])->values(),
            'pending' => collect($sizes['sizes'] ?? [])->map(fn ($s) => ['connection' => $s['connection'], 'queue' => $s['queue'], 'size' => $s['size']])->values(),
        ]]);
    }

    /**
     * GET /api/v1/apps/{domain}/queues/failed
     */
    public function failedJobs(Request $request, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);

        return response()->json(['data' => array_values((array) $this->agentDo(fn () => $agent->call('laravel.probe', $app->agentApp() + ['kind' => 'failed'], config('servik.agent.job_timeout'))))]);
    }

    public function restartQueues(Request $request, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);
        $this->agentDo(fn () => $agent->call('laravel.queue.restart', $app->target(), config('servik.agent.job_timeout')));
        $this->audit($request, $app, __('Kuyruklar'), __('İşçiler yeniden başlatıldı.'));

        return response()->json(['message' => __('İşçiler yeniden başlatıldı.')]);
    }

    /**
     * POST /api/v1/apps/{domain}/queues/retry {id?}: queue:retry of one
     * failed job, or of all of them.
     */
    public function retryJobs(Request $request, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);
        $id = (string) ($request->validate(['id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/']])['id'] ?? '');
        $result = $this->agentDo(fn () => $agent->call('laravel.run', $app->agentApp() + ['tool' => 'artisan', 'args' => ['queue:retry', $id === '' ? 'all' : $id]], config('servik.agent.job_timeout')));
        if (($result['exit_code'] ?? 1) !== 0) {
            return $this->refuse(trim((string) ($result['output'] ?? '')) ?: __('Komut başarısız oldu.'), 502);
        }
        $message = $id === '' ? __('Başarısız işler yeniden kuyruğa alındı.') : __('İş yeniden kuyruğa alındı.');
        $this->audit($request, $app, __('Kuyruklar'), $message);

        return response()->json(['message' => $message, 'data' => ['output' => trim((string) ($result['output'] ?? ''))]]);
    }

    /**
     * GET /api/v1/apps/{domain}/maintenance
     */
    public function maintenance(Request $request, Maintenance $maintenance): JsonResponse
    {
        return response()->json(['data' => $this->maintenanceState($this->app($request), $maintenance)]);
    }

    /**
     * POST /api/v1/apps/{domain}/maintenance {down, mode?, allow?, secret?, retry?, html?}:
     * mode servik (default), custom (html) or app (php artisan down);
     * allow lists addresses or networks that still get through.
     */
    public function setMaintenance(Request $request, Maintenance $maintenance): JsonResponse
    {
        $app = $this->app($request);
        $data = $request->validate([
            'down' => ['required', 'boolean'],
            'mode' => ['nullable', Rule::in(array_keys(LaravelApp::MAINTENANCE_MODES))],
            'allow' => ['nullable', 'array', 'max:'.MaintenanceSection::MAX_ALLOW],
            'allow.*' => ['string', fn ($attr, $value, $fail) => MaintenanceSection::validAddress((string) $value) ? null : $fail(__(':line bir IP adresi veya ağ (örneğin 10.0.0.0/24) değil.', ['line' => $value]))],
            'secret' => ['nullable', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'retry' => ['nullable', 'integer', 'between:0,86400'],
            'html' => ['nullable', 'string', 'max:200000'],
        ], ['secret.regex' => __('Yalnızca harf, rakam, - ve _ kullanın.')], ['secret' => __('gizli anahtar'), 'retry' => __('tekrar deneme süresi')]);
        if (! $data['down']) {
            $error = $maintenance->up($app);
            if ($error !== null) {
                return $this->refuse($error, 502);
            }
            $this->audit($request, $app, __('Bakım modu'), __('Uygulama yeniden açıldı.'));

            return response()->json(['message' => __('Uygulama yeniden açıldı.'), 'data' => $this->maintenanceState($app->fresh(['site']), $maintenance)]);
        }
        $mode = $data['mode'] ?? ($app->maintenance_mode === 'app' ? 'servik' : $app->maintenance_mode);
        $html = $data['html'] ?? $app->maintenance_html;
        if ($mode === 'custom' && ($html === null || $html === '')) {
            return $this->refuse(__('Ziyaretçilere gösterilecek HTML\'i yazın.'));
        }
        $error = $maintenance->down($app, $mode, [
            'maintenance_secret' => $data['secret'] ?? $app->maintenance_secret ?? Str::lower(Str::random(24)),
            'maintenance_retry' => (int) ($data['retry'] ?? $app->maintenance_retry),
            'maintenance_allow' => array_key_exists('allow', $data) ? (implode("\n", array_values(array_unique($data['allow'] ?? []))) ?: null) : $app->maintenance_allow,
            'maintenance_html' => $html,
        ]);
        if ($error !== null) {
            return $this->refuse($error, 502);
        }
        $this->audit($request, $app, __('Bakım modu'), __('Uygulama bakım modunda.'));

        return response()->json(['message' => __('Uygulama bakım modunda.'), 'data' => $this->maintenanceState($app->fresh(['site']), $maintenance)]);
    }

    /**
     * GET /api/v1/apps/{domain}/logs[?file=][&level=][&search=][&limit=]:
     * the newest entries of storage/logs, newest first.
     */
    public function logs(Request $request, AgentClient $agent): JsonResponse
    {
        $app = $this->app($request);
        $level = (string) $request->query('level', '');
        $search = trim((string) $request->query('search', ''));
        $file = (string) $request->query('file', '');
        if ($file !== '' && ! preg_match('/^[A-Za-z0-9._-]+\.log$/', $file)) {
            return $this->refuse(__('Günlük dosyası adı geçersiz.'));
        }
        $logs = $this->agentDo(fn () => $agent->call('laravel.logs', $app->agentApp() + ['file' => $file, 'bytes' => 512 * 1024], config('servik.agent.job_timeout')));
        $entries = collect(LogParser::parse((string) ($logs['content'] ?? '')))
            ->when($level !== '', fn ($c) => $c->filter(fn ($e) => LogParser::atLeast($e['level'], $level)))
            ->when($search !== '', fn ($c) => $c->filter(fn ($e) => mb_stripos($e['message'].' '.$e['detail'], $search) !== false))
            ->take(min(1000, max(1, (int) $request->query('limit', 100))))
            ->values();

        return response()->json(['data' => [
            'file' => $logs['file'] ?? null,
            'files' => $logs['files'] ?? [],
            'truncated' => (bool) ($logs['truncated'] ?? false),
            'entries' => $entries,
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function maintenanceState(LaravelApp $app, Maintenance $maintenance): array
    {
        $status = $maintenance->status($app);
        $artisan = $status['maintenance'] ?? null;
        $down = $app->nginxMaintenance() || $artisan !== null;
        $secret = $app->nginxMaintenance() ? (string) $app->maintenance_secret : (string) ($artisan['secret'] ?? '');
        $site = $app->site;

        return [
            'down' => $down,
            'mode' => $down ? ($app->nginxMaintenance() ? $app->maintenance_mode : 'app') : null,
            'allow' => $app->nginxMaintenance() ? $app->maintenanceAllowList() : [],
            'bypass_url' => $down && $secret !== '' ? ($site->hasHttps() ? 'https://' : 'http://').$site->domain.'/'.$secret : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function command(LaravelCommand $c): array
    {
        return [
            'id' => $c->id,
            'tool' => $c->tool,
            'command' => $c->command,
            'run' => $c->run,
            'status' => $c->status,
            'exit_code' => $c->exit_code,
            'error' => $c->error,
            'done' => ! in_array($c->status, ['queued', 'running'], true),
            'created_at' => $c->created_at?->toIso8601String(),
            'finished_at' => $c->finished_at?->toIso8601String(),
        ];
    }

    /**
     * Runs an agent call; a failure ends the request with the reason (put
     * in $message's :value when given).
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function agentDo(callable $call, ?string $message = null): mixed
    {
        try {
            return $call();
        } catch (AgentException $e) {
            $reason = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : $e->reason;

            throw new HttpResponseException($this->refuse($message !== null ? str_replace(':value', $reason, $message) : $reason, $e instanceof AgentUnavailableException ? 503 : 502));
        }
    }

    private function audit(Request $request, LaravelApp $app, string $section, string $message): void
    {
        AuditLog::record($message, $request->user(), 'api', [
            'actor' => $this->actor($request),
            'section' => 'Laravel · '.$app->site->domain.' · '.$section,
            'url' => route('laravel.show', ['app' => $app->id], false),
        ]);
    }

    private function actor(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User ? 'API: '.$request->attributes->get('api_key')?->name.' ('.$user->name.')' : (string) $request->attributes->get('actor');
    }

    private function refuse(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    private function app(Request $request): LaravelApp
    {
        return $request->attributes->get('laravel_app')->loadMissing('site.systemUser', 'site.certificate');
    }
}
