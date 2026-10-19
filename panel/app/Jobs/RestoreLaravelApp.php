<?php

namespace App\Jobs;

use App\Models\LaravelBackup;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\Backups;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Puts parts of a backup back into its application: first, when asked, a
 * backup of the same parts as they are now (so the restore can be undone),
 * then the restore itself, logged to the backup's restore run.
 */
class RestoreLaravelApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 14700;

    /**
     * @param  list<string>  $parts
     */
    public function __construct(public LaravelBackup $backup, public array $parts, public bool $safety = true, public ?string $userName = null) {}

    public function handle(AgentClient $agent, Backups $backups): void
    {
        $backup = $this->backup->fresh('app.site.systemUser');
        if ($backup === null || $backup->restore_status !== 'queued') {
            return;
        }
        $app = $backup->app;
        $backup->update(['restore_status' => 'running']);
        if ($this->safety) {
            $before = $backups->createNow($app, $this->parts, 'restore', note: $backup->created_at->format('d.m.Y H:i').' yedeği geri yüklenmeden önce');
            $before->update(['user_name' => $this->userName]);
            if ($before->status !== 'succeeded') {
                $backup->update(['restore_status' => 'failed', 'restore_error' => 'Geri yüklemeden önceki yedek alınamadı: '.$before->error, 'restored_at' => now()]);

                return;
            }
        }
        try {
            $result = $agent->call('laravel.backup.restore', $app->agentApp() + [
                'id' => $backup->key,
                'parts' => $this->parts,
                'run' => $backup->restore_run,
            ], config('servik.agent.backup_timeout'));
        } catch (AgentException $e) {
            $backup->update(['restore_status' => 'failed', 'restore_error' => $e->reason ?: $e->getMessage(), 'restored_at' => now()]);

            return;
        }
        if (($result['current'] ?? '') !== '' && $app->isManaged()) {
            $app->update(['current_release' => $result['current']]);
        }
        $backup->update(['restore_status' => 'succeeded', 'restore_error' => null, 'restored_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        $backup = $this->backup->fresh();
        if ($backup !== null && in_array($backup->restore_status, ['queued', 'running'], true)) {
            $backup->update(['restore_status' => 'failed', 'restore_error' => $e?->getMessage() ?? 'Geri yükleme yarıda kaldı.', 'restored_at' => now()]);
        }
    }
}
