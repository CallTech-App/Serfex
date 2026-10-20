<?php

namespace App\Services\Laravel;

use App\Enums\LaravelAppStatus;
use App\Jobs\BackupLaravelApp;
use App\Jobs\PurgeLaravelBackup;
use App\Jobs\RestoreLaravelApp;
use App\Models\LaravelApp;
use App\Models\LaravelBackup;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Backups of one Laravel application, kept by the agent under
 * /var/lib/servik/backups/laravel/<domain>/ and listed in the panel.
 */
class Backups
{
    /** Backups taken before deploys that are kept. */
    public const KEEP_DEPLOY = 5;

    /** Minutes a deleted backup can still be brought back. */
    public const UNDO_MINUTES = 10;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Records a backup and queues it.
     *
     * @param  list<string>  $parts
     */
    public function create(LaravelApp $app, array $parts, string $kind = 'manual', ?User $user = null, ?string $note = null): LaravelBackup
    {
        $backup = $this->record($app, $parts, $kind, $user, $note);
        BackupLaravelApp::dispatch($backup);

        return $backup;
    }

    /**
     * Records a backup and takes it now. Returns the backup; its status
     * says whether it worked.
     *
     * @param  list<string>  $parts
     */
    public function createNow(LaravelApp $app, array $parts, string $kind, ?User $user = null, ?string $note = null): LaravelBackup
    {
        $backup = $this->record($app, $parts, $kind, $user, $note);
        $this->take($backup);

        return $backup->refresh();
    }

    /**
     * Asks the agent for the backup and records the outcome.
     */
    public function take(LaravelBackup $backup): void
    {
        $app = $backup->app->loadMissing('site.systemUser');
        try {
            $result = $this->agent->call('laravel.backup.create', $app->agentApp() + [
                'id' => $backup->key,
                'parts' => $backup->parts,
                'run' => $backup->run,
            ], config('servik.agent.backup_timeout'));
        } catch (AgentException $e) {
            $backup->update(['status' => 'failed', 'error' => $e->reason ?: $e->getMessage(), 'finished_at' => now()]);

            return;
        }
        $sizes = (array) ($result['parts'] ?? []);
        $skipped = (array) ($result['skipped'] ?? []);
        $backup->update([
            'status' => 'succeeded',
            'parts' => array_values(array_intersect(array_keys(LaravelBackup::PARTS), array_keys($sizes))),
            'part_sizes' => $sizes,
            'size' => (int) ($result['size'] ?? 0),
            'release' => ($result['release'] ?? '') ?: null,
            'database_driver' => ($result['database'] ?? '') ?: null,
            'error' => $skipped === [] ? null : collect($skipped)->map(fn ($why, $part) => (LaravelBackup::PARTS[$part] ?? $part).': '.$why)->join('; '),
            'finished_at' => now(),
        ]);
        $this->prune($app);
    }

    /**
     * Queues a restore of $parts from $backup. With $safety, the current
     * state of those parts is backed up first, so the restore can be undone.
     *
     * @param  list<string>  $parts
     */
    public function restore(LaravelBackup $backup, array $parts, bool $safety, ?User $user = null): void
    {
        $backup->update([
            'restore_run' => 'restore-'.Str::lower(Str::random(12)),
            'restore_status' => 'queued',
            'restore_error' => null,
            'restored_at' => null,
        ]);
        RestoreLaravelApp::dispatch($backup, $parts, $safety, $user?->name);
    }

    /**
     * Hides a backup and removes it from the server in a few minutes,
     * unless brought back.
     */
    public function delete(LaravelBackup $backup): void
    {
        $backup->update(['status' => 'deleted']);
        PurgeLaravelBackup::dispatch($backup)->delay(now()->addMinutes(self::UNDO_MINUTES));
    }

    public function undoDelete(LaravelBackup $backup): bool
    {
        if ($backup->status !== 'deleted') {
            return false;
        }
        $backup->update(['status' => 'succeeded']);

        return true;
    }

    /**
     * Removes a backup from the server and the panel now.
     */
    public function purge(LaravelBackup $backup): void
    {
        $backup->loadMissing('app.site');
        try {
            $this->agent->call('laravel.backup.delete', ['domain' => $backup->app->site->domain, 'id' => $backup->key], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            // A backup the agent cannot reach stays listed as failed rather
            // than leaving a file nobody sees.
            $backup->update(['status' => 'failed', 'error' => __('Sunucudan silinemedi: :reason', ['reason' => ($e->reason ?: $e->getMessage())])]);

            return;
        }
        $backup->delete();
    }

    /**
     * Applies retention: the newest backup_keep scheduled backups and
     * KEEP_DEPLOY pre-deploy backups stay; manual ones stay until deleted.
     */
    public function prune(LaravelApp $app): void
    {
        foreach (['scheduled' => max(1, $app->backup_keep), 'deploy' => self::KEEP_DEPLOY] as $kind => $keep) {
            $old = $app->backups()->where('kind', $kind)->where('status', 'succeeded')->skip($keep)->take(100)->get();
            foreach ($old as $backup) {
                $this->purge($backup);
            }
        }
    }

    /**
     * True when the application's scheduled backup is due at $now.
     */
    public function due(LaravelApp $app, Carbon $now): bool
    {
        if ($app->backup_schedule === 'off' || $app->status !== LaravelAppStatus::Active || $app->isPreview()) {
            return false;
        }
        [$h, $m] = array_map('intval', explode(':', $app->backup_time ?: '03:00') + [1 => 0]);
        // The time is the panel's (Araçlar ve Ayarlar → Genel).
        $at = $now->copy()->timezone(config('servik.timezone'))->setTime($h, $m);
        if ($now->lt($at)) {
            $at->subDay();
        }
        $at->utc();
        $last = $app->backups()->where('kind', 'scheduled')->whereIn('status', ['running', 'succeeded', 'failed'])->max('created_at');
        if ($last === null) {
            return true;
        }
        $last = Carbon::parse($last);

        return $app->backup_schedule === 'weekly'
            ? $last->lt($at->copy()->subDays(6))
            : $last->lt($at);
    }

    /**
     * @param  list<string>  $parts
     */
    private function record(LaravelApp $app, array $parts, string $kind, ?User $user, ?string $note): LaravelBackup
    {
        $random = Str::lower(Str::random(16));

        return $app->backups()->create([
            'key' => now()->format('Ymd-His').'-'.$random,
            'kind' => $kind,
            'status' => 'running',
            'parts' => array_values(array_intersect(array_keys(LaravelBackup::PARTS), $parts)),
            'run' => 'backup-'.$random,
            'user_name' => $user?->name,
            'note' => $note,
        ]);
    }
}
