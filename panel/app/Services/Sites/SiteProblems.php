<?php

namespace App\Services\Sites;

use App\Enums\DeploymentStatus;
use App\Enums\GitDeploymentStatus;
use App\Models\CronJob;
use App\Models\GitDeployment;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Services\Laravel\BackgroundWatch;
use Illuminate\Support\Str;

/**
 * What is wrong with the sites now, for Genel Bakış and the notifications:
 * a site that does not answer, a deployment that failed or was rolled back,
 * a scheduled task that keeps failing, a queue worker or process that keeps
 * crashing.
 */
class SiteProblems
{
    /** How long a failed deployment stays a problem (unless deployed again). */
    public const DEPLOY_HOURS = 24;

    /**
     * key => {kind, tone, title, text, url, action}; url is a path.
     *
     * @return array<string, array{kind: string, tone: string, title: string, text: ?string, url: string, action: string}>
     */
    public function all(): array
    {
        $out = [];
        foreach (Site::where('uptime_state', 'down')->where('uptime_enabled', true)->orderBy('uptime_since')->get() as $site) {
            $out['site:'.$site->id] = [
                'kind' => 'site', 'tone' => 'crit',
                'title' => __(':domain yanıt vermiyor', ['domain' => $site->primaryHost()]),
                'text' => trim($site->uptime_error.($site->uptime_cause ? ' · '.$site->uptime_cause : '')),
                'url' => route('sites.show', $site, false), 'action' => __('Siteye git'),
            ];
        }

        $since = now()->subHours(self::DEPLOY_HOURS);
        $apps = LaravelApp::with(['site', 'latestDeployment'])
            ->whereHas('latestDeployment', fn ($d) => $d->whereIn('status', [DeploymentStatus::Failed, DeploymentStatus::RolledBack])->where('finished_at', '>=', $since))->get();
        foreach ($apps as $app) {
            $d = $app->latestDeployment;
            $domain = $app->site?->domain ?? '';
            $out['deploy:laravel:'.$d->id] = [
                'kind' => 'deploy', 'tone' => 'crit',
                'title' => $d->status === DeploymentStatus::RolledBack ? __(':domain dağıtımı geri alındı', ['domain' => $domain]) : __(':domain dağıtımı başarısız', ['domain' => $domain]),
                'text' => $d->status === DeploymentStatus::RolledBack
                    ? __('Dağıtım #:number sağlık kontrolünü geçemedi, önceki sürüm yayında.', ['number' => $d->number]).($d->error ? ' '.Str::limit($d->error, 200) : '')
                    : __('Dağıtım #:number:', ['number' => $d->number]).' '.Str::limit((string) ($d->error ?: __('ayrıntılar dağıtımın günlüğünde')), 200),
                'url' => route('laravel.show', ['app' => $app->id, 'section' => 'deployments'], false), 'action' => __('Dağıtımı gör'),
            ];
        }

        $latest = GitDeployment::selectRaw('max(id)')->groupBy('git_repository_id');
        $git = GitDeployment::with('repository.site')->whereIn('id', $latest)
            ->whereIn('status', [GitDeploymentStatus::Failed, GitDeploymentStatus::CommandsFailed])->where('finished_at', '>=', $since)->get();
        foreach ($git as $d) {
            $site = $d->repository?->site;
            if ($site === null) {
                continue;
            }
            $out['deploy:git:'.$d->id] = [
                'kind' => 'deploy', 'tone' => 'crit',
                'title' => __(':domain Git dağıtımı başarısız', ['domain' => $site->domain]),
                'text' => Str::limit((string) ($d->error ?: __('ayrıntılar dağıtımın günlüğünde')), 200),
                'url' => route('sites.git', $site, false), 'action' => __('Dağıtımı gör'),
            ];
        }

        $jobs = CronJob::with('site')->where('enabled', true)->where('failed_runs', '>=', CronJobs::FAILURES)->get();
        foreach ($jobs as $job) {
            if ($job->site === null) {
                continue;
            }
            $out['cron:'.$job->id] = [
                'kind' => 'cron', 'tone' => 'warn',
                'title' => __(':domain zamanlanmış görevi üst üste :count kez başarısız', ['domain' => $job->site->domain, 'count' => $job->failed_runs]),
                'text' => Str::limit($job->note ?: $job->command, 200),
                'url' => route('sites.cron', $job->site, false), 'action' => __('Göreve git'),
            ];
        }

        foreach (LaravelApp::with('site')->whereNotNull('watch')->get() as $app) {
            if ($app->site === null) {
                continue;
            }
            $domain = $app->site->domain;
            foreach (BackgroundWatch::failingTasks($app) as $t) {
                $out['cron:laravel:'.$app->id.':'.substr(sha1($t['command']), 0, 12)] = [
                    'kind' => 'cron', 'tone' => 'warn',
                    'title' => __(':domain zamanlayıcısında bir görev üst üste :count kez başarısız', ['domain' => $domain, 'count' => $t['failures']]),
                    'text' => Str::limit($t['command'], 200),
                    'url' => route('laravel.show', ['app' => $app->id, 'section' => 'scheduler'], false), 'action' => __('Zamanlayıcıya git'),
                ];
            }
            foreach (BackgroundWatch::crashing($app) as $u) {
                $worker = $u['kind'] === 'worker';
                $out['queue:'.$app->id.':'.$u['kind'].':'.$u['name']] = [
                    'kind' => 'queue', 'tone' => $u['state'] === 'failed' ? 'crit' : 'warn',
                    'title' => match (true) {
                        $u['state'] === 'failed' && $worker => __(':domain kuyruk işçisi durdu', ['domain' => $domain]),
                        $u['state'] === 'failed' => __(':domain :name süreci durdu', ['domain' => $domain, 'name' => $u['name']]),
                        $worker => __(':domain kuyruk işçisi sürekli çöküyor', ['domain' => $domain]),
                        default => __(':domain :name süreci sürekli çöküyor', ['domain' => $domain, 'name' => $u['name']]),
                    },
                    'text' => ($worker ? __(':name işçisi', ['name' => $u['name']]).' · ' : '').($u['state'] === 'failed'
                        ? __('Sistem çok sık çöktüğü için yeniden başlatmayı bıraktı; günlüğüne bakıp yeniden başlatın.')
                        : __('Son :minutes dakikada :count kez çöküp yeniden başladı; günlüğüne bakın.', ['minutes' => BackgroundWatch::WINDOW, 'count' => $u['crashes']])),
                    'url' => route('laravel.show', ['app' => $app->id, 'section' => $worker ? 'queues' : 'processes'], false), 'action' => $worker ? __('Kuyruğa git') : __('Sürece git'),
                ];
            }
        }

        return $out;
    }
}
