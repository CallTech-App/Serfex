<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\CronJob;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Sites\CronJobs;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Zamanlanmış görevler: a site's cron tasks, run as the site's user in
 * the site folder with the site's PHP, each with its last runs and their
 * output. Pausing and deleting can be undone.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteCron extends Component
{
    public int $siteId;

    public bool $editing = false;

    public ?int $editId = null;

    /** A ready schedule, or "custom" for $schedule. */
    public string $preset = '*/5 * * * *';

    public string $schedule = '';

    public string $command = '';

    public string $note = '';

    public int $timeout = 3600;

    public ?int $deletingId = null;

    /** The task whose runs are open. */
    public ?int $historyId = null;

    /** @var array{runs: list<array<string, mixed>>, running: bool, error: ?string}|null */
    public ?array $history = null;

    /** The run whose output is shown in the history. */
    public ?string $runId = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'laravelApp')->findOrFail($this->siteId);
    }

    private function task(int $id): CronJob
    {
        return CronJob::where('site_id', $this->siteId)->findOrFail($id);
    }

    public function openCreate(CronJobs $jobs): void
    {
        if ($refusal = $jobs->refusal($this->site()->systemUser)) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->editId = null;
        $this->preset = '*/5 * * * *';
        $this->schedule = '';
        $this->command = $this->note = '';
        $this->timeout = 3600;
        $this->editing = true;
    }

    public function openEdit(int $id): void
    {
        $task = $this->task($id);
        $this->resetValidation();
        $this->editId = $task->id;
        $this->preset = isset(CronJob::PRESETS[$task->schedule]) ? $task->schedule : 'custom';
        $this->schedule = $task->schedule;
        $this->command = $task->command;
        $this->note = (string) $task->note;
        $this->timeout = $task->timeout;
        $this->editing = true;
    }

    public function save(CronJobs $jobs): void
    {
        $site = $this->site();
        $this->command = trim($this->command);
        $this->note = trim($this->note);
        $schedule = CronJobs::normalizeSchedule($this->preset === 'custom' ? $this->schedule : $this->preset);
        $this->resetValidation();
        if ($schedule === null) {
            $this->addError('schedule', 'Beş alanlı bir cron zamanı yazın: dakika saat gün ay haftanın-günü, örneğin 30 2 * * *.');
        }
        if ($problem = CronJobs::commandProblem($this->command)) {
            $this->addError('command', $problem);
        }
        if (mb_strlen($this->note) > 100) {
            $this->addError('note', 'Not en fazla 100 karakter olabilir.');
        }
        if (! isset(CronJobs::TIMEOUTS[$this->timeout])) {
            $this->addError('timeout', 'Listeden bir süre seçin.');
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $task = $this->editId ? $this->task($this->editId) : null;
        if ($task === null && ($refusal = $jobs->refusal($site->systemUser))) {
            $this->addError('command', $refusal);

            return;
        }

        $values = ['schedule' => $schedule, 'command' => $this->command, 'note' => $this->note ?: null, 'timeout' => $this->timeout];
        $previous = $task?->only(array_keys($values));
        try {
            $task = $this->change($site, $jobs, fn () => $task ? tap($task)->update($values) : CronJob::create(['site_id' => $site->id, ...$values]));
        } catch (AgentException $e) {
            $this->addError('command', 'Sunucu görevi kaydedemedi: '.$e->reason);

            return;
        }
        $this->editing = false;
        Quotas::forget();
        if ($previous === null) {
            $this->dispatch('toast', message: 'Zamanlanmış görev eklendi: '.$task->scheduleLabel().'.', source: $this->getId(), undo: ['method' => 'undoCreate', 'args' => [$task->id]]);
        } else {
            $this->dispatch('toast', message: 'Zamanlanmış görev kaydedildi.', source: $this->getId(), undo: ['method' => 'undoEdit', 'args' => [$task->id, $previous]]);
        }
    }

    public function undoCreate(CronJobs $jobs, int $id): void
    {
        $task = CronJob::where('site_id', $this->siteId)->find($id);
        if ($task === null) {
            return;
        }
        $this->changeOrToast($jobs, fn () => $task->forceDelete(), 'Eklenen görev geri alındı.');
        Quotas::forget();
    }

    /**
     * @param  array{schedule: string, command: string, note: ?string, timeout: int}  $previous
     */
    public function undoEdit(CronJobs $jobs, int $id, array $previous): void
    {
        $task = CronJob::where('site_id', $this->siteId)->find($id);
        $schedule = CronJobs::normalizeSchedule((string) ($previous['schedule'] ?? ''));
        $command = (string) ($previous['command'] ?? '');
        if ($task === null || $schedule === null || CronJobs::commandProblem($command) !== null || ! isset(CronJobs::TIMEOUTS[(int) ($previous['timeout'] ?? 0)])) {
            return;
        }
        $note = mb_substr(trim((string) ($previous['note'] ?? '')), 0, 100);
        $this->changeOrToast($jobs, fn () => $task->update(['schedule' => $schedule, 'command' => $command, 'note' => $note ?: null, 'timeout' => (int) $previous['timeout']]),
            'Görevdeki değişiklik geri alındı.');
    }

    public function pause(CronJobs $jobs, int $id): void
    {
        $this->setEnabled($jobs, $id, false, true);
    }

    public function resume(CronJobs $jobs, int $id): void
    {
        $this->setEnabled($jobs, $id, true, true);
    }

    private function setEnabled(CronJobs $jobs, int $id, bool $enabled, bool $withUndo): void
    {
        $task = $this->task($id);
        if ($task->enabled === $enabled) {
            return;
        }
        try {
            $this->change($this->site(), $jobs, fn () => $task->update(['enabled' => $enabled]));
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Sunucu görevi değiştiremedi: '.$e->reason);

            return;
        }
        $this->dispatch('toast', message: $enabled ? 'Görev yeniden çalışacak.' : 'Görev duraklatıldı; zamanı gelince çalışmaz.',
            source: $this->getId(), undo: $withUndo ? ['method' => $enabled ? 'pause' : 'resume', 'args' => [$task->id]] : null);
    }

    public function runNow(CronJobs $jobs, int $id): void
    {
        $task = $this->task($id)->setRelation('site', $this->site());
        try {
            $started = $jobs->run($task);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Görev başlatılamadı: '.$e->reason);

            return;
        }
        $this->dispatch('toast', message: $started ? 'Görev başlatıldı; çıktısı geçmişte görünecek.' : 'Görev zaten çalışıyor.');
        if ($started) {
            $task->update(['last_run_at' => now(), 'last_status' => 'running']);
        }
    }

    public function confirmDelete(int $id): void
    {
        $this->deletingId = $this->task($id)->id;
    }

    public function delete(CronJobs $jobs): void
    {
        if ($this->deletingId === null) {
            return;
        }
        $task = $this->task($this->deletingId);
        $this->deletingId = null;
        try {
            $this->change($this->site(), $jobs, fn () => $task->delete());
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Sunucu görevi silemedi: '.$e->reason);

            return;
        }
        Quotas::forget();
        $this->dispatch('toast', message: 'Zamanlanmış görev silindi.', source: $this->getId(), undo: ['method' => 'restore', 'args' => [$task->id]]);
    }

    public function restore(CronJobs $jobs, int $id): void
    {
        $task = CronJob::onlyTrashed()->where('site_id', $this->siteId)->find($id);
        if ($task === null) {
            return;
        }
        $this->changeOrToast($jobs, fn () => $task->restore(), 'Zamanlanmış görev geri alındı.');
        Quotas::forget();
    }

    public function openHistory(CronJobs $jobs, int $id): void
    {
        $task = $this->task($id)->setRelation('site', $this->site());
        $this->historyId = $task->id;
        $this->runId = null;
        try {
            $runs = $jobs->runs($task);
            $this->history = ['runs' => $runs['runs'], 'running' => $runs['running'], 'error' => null];
            $this->runId = $runs['runs'][0]['id'] ?? null;
        } catch (AgentException $e) {
            $this->history = ['runs' => [], 'running' => false, 'error' => 'Geçmiş okunamadı: '.$e->reason];
        }
    }

    public function showRun(string $id): void
    {
        $this->runId = $id;
    }

    public function closeHistory(): void
    {
        $this->historyId = null;
        $this->history = null;
        $this->runId = null;
    }

    public function render(CronJobs $jobs): View
    {
        $site = $this->site();
        $customer = $site->customer();

        return view('livewire.sites.cron', [
            'site' => $site,
            'tasks' => CronJob::where('site_id', $site->id)->orderBy('id')->get(),
            'usage' => $customer ? app(Quotas::class)->report($customer)['cron_jobs'] : null,
            'refusal' => $jobs->refusal($site->systemUser),
        ])->title('Zamanlanmış görevler · '.$site->domain.' · Servik');
    }

    /**
     * Runs $change and writes the site's tasks to the server; when the
     * server refuses, the change is rolled back.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     *
     * @throws AgentException
     */
    private function change(Site $site, CronJobs $jobs, callable $change): mixed
    {
        return DB::transaction(function () use ($site, $jobs, $change) {
            $result = $change();
            $jobs->apply($site);

            return $result;
        });
    }

    private function changeOrToast(CronJobs $jobs, callable $change, string $message): void
    {
        try {
            $this->change($this->site(), $jobs, $change);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Sunucu görevi değiştiremedi: '.$e->reason);

            return;
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $this->dispatch('toast', message: $message);
    }
}
