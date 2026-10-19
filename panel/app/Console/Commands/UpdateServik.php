<?php

namespace App\Console\Commands;

use App\Models\PanelUpdate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Updates\Updates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Güncellemeler from SSH: checks for a newer release, updates to it (or
 * returns to the previous release) the way the page does, and follows the
 * steps until the run ends. The panel's queue worker takes the backup and
 * starts the run, so it must be running.
 */
#[Signature('servik:update
    {--check : Only look for newer releases}
    {--rollback : Return to the previous release}
    {--target= : Install this version instead of the newest}
    {--yes : Do not ask to confirm}')]
#[Description('Update Servik to its newest release, or return to the previous one')]
class UpdateServik extends Command
{
    /** Seconds to follow a run before giving up on it. */
    private const FOLLOW = 7200;

    public function handle(Updates $updates, AgentClient $agent): int
    {
        $status = $updates->check();
        if (is_string($status)) {
            $this->error($status);

            return self::FAILURE;
        }
        $this->line('Kurulu: Servik '.$status['version'].(($status['previous'] ?? '') !== '' ? ' (önceki: '.$status['previous'].')' : ''));
        $release = $updates->available($status);
        if ($this->option('check')) {
            $this->info($release ? 'Servik '.$release['version'].' hazır.' : 'Servik güncel.');

            return self::SUCCESS;
        }

        $rollback = (bool) $this->option('rollback');
        $question = $rollback
            ? 'Servik '.($status['previous'] ?? '?').' sürümüne dönülsün mü?'
            : 'Servik '.($this->option('target') ?: ($release['version'] ?? '?')).' kurulsun mu?';
        if (! $this->option('yes') && ! $this->confirm($question, true)) {
            return self::FAILURE;
        }
        $result = $rollback ? $updates->rollback(null) : $updates->start(null, $this->option('target') ?: null);
        if (is_string($result)) {
            $this->error($result);

            return self::FAILURE;
        }

        return $this->follow($result, $updates, $agent);
    }

    private function follow(PanelUpdate $update, Updates $updates, AgentClient $agent): int
    {
        $this->line('Panel yedeği alınıyor…');
        $offset = 0;
        $deadline = time() + self::FOLLOW;
        while (time() < $deadline) {
            $update->refresh();
            if (in_array($update->status, ['queued', 'backing_up'], true)) {
                sleep(1);

                continue;
            }
            try {
                $chunk = (array) $agent->call('update.run.read', ['run' => $update->run, 'offset' => $offset]);
            } catch (AgentException) {
                // The agent restarts during the update.
                sleep(2);

                continue;
            }
            if (($chunk['data'] ?? '') !== '') {
                $this->output->write((string) $chunk['data']);
            }
            $offset = (int) ($chunk['offset'] ?? $offset);
            $more = $offset < (int) ($chunk['size'] ?? 0);
            if (! $update->isRunning() || (($chunk['done'] ?? false) && ! $more)) {
                $updates->reconcile();
                $update->refresh();
                if (! $update->isRunning()) {
                    break;
                }
            }
            if (! $more) {
                sleep(1);
            }
        }

        return match ($update->status) {
            'succeeded' => $this->done('Servik '.$update->to_version.' çalışıyor.'),
            'rolled_back' => $this->failed('Servik '.$update->to_version.' kurulamadı, '.$update->from_version.' sürümüne dönüldü: '.$update->error, 2),
            default => $this->failed($update->isRunning() ? 'Güncelleme bitmedi; Güncellemeler sayfasından izleyin.' : 'Başarısız: '.$update->error, 1),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message, int $code): int
    {
        $this->error($message);

        return $code;
    }
}
