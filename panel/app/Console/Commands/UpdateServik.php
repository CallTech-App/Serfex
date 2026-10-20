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
        $this->line(($status['previous'] ?? '') !== '' ? __('Kurulu: Servik :version (önceki: :previous)', ['version' => $status['version'], 'previous' => $status['previous']]) : __('Kurulu: Servik :version', ['version' => $status['version']]));
        $release = $updates->available($status);
        if ($this->option('check')) {
            $this->info($release ? __('Servik :version hazır.', ['version' => $release['version']]) : __('Servik güncel.'));

            return self::SUCCESS;
        }

        $rollback = (bool) $this->option('rollback');
        $question = $rollback
            ? __('Servik :version sürümüne dönülsün mü?', ['version' => $status['previous'] ?? '?'])
            : __('Servik :version kurulsun mu?', ['version' => $this->option('target') ?: ($release['version'] ?? '?')]);
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
        $this->line(__('Panel yedeği alınıyor…'));
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
            'succeeded' => $this->done(__('Servik :to_version çalışıyor.', ['to_version' => $update->to_version])),
            'rolled_back' => $this->failed(__('Servik :to_version kurulamadı, :from_version sürümüne dönüldü: :error', ['to_version' => $update->to_version, 'from_version' => $update->from_version, 'error' => $update->error]), 2),
            default => $this->failed($update->isRunning() ? __('Güncelleme bitmedi; Güncellemeler sayfasından izleyin.') : __('Başarısız: :error', ['error' => $update->error]), 1),
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
