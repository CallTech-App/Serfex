<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Laravel\ReadsRunLogs;
use App\Models\LaravelBackup;
use App\Services\Customers\Quotas;
use App\Services\Laravel\Backups as BackupService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;

/**
 * Backups of this application only: code, .env, storage and database, on
 * demand, on a schedule and before deploys; restored without touching the
 * account's other sites.
 */
#[Lazy]
class Backups extends Section
{
    use ReadsRunLogs;

    protected const ABILITY = 'operate';

    protected const VIEW_METHODS = ['showLog', 'closeLog'];

    protected const MANAGE_METHODS = ['openRestore', 'restore', 'undoRestore'];

    public bool $creating = false;

    /** @var list<string> */
    public array $parts = ['code', 'env', 'storage', 'database'];

    public bool $scheduling = false;

    public string $schedule = 'off';

    public string $time = '03:00';

    public int $keep = 7;

    public bool $restoring = false;

    public ?int $restoreId = null;

    /** @var list<string> */
    public array $restoreParts = [];

    public bool $safety = true;

    public bool $viewingLog = false;

    public ?string $logRun = null;

    public ?string $logTitle = null;

    public function openCreate(): void
    {
        $this->parts = $this->app()->isManaged() ? array_keys(LaravelBackup::PARTS) : ['env', 'storage', 'database'];
        $this->resetValidation();
        $this->creating = true;
    }

    public function create(BackupService $backups): void
    {
        $this->validate(['parts' => ['required', 'array', 'min:1'], 'parts.*' => [Rule::in(array_keys(LaravelBackup::PARTS))]],
            ['parts.required' => __('En az bir parça seçin.'), 'parts.min' => __('En az bir parça seçin.')]);
        if (($refusal = app(Quotas::class)->refusal($this->app()->site?->customer(), 'disk')) !== null) {
            $this->addError('parts', $refusal);

            return;
        }
        $backups->create($this->app(), $this->parts, 'manual', auth()->user());
        $this->creating = false;
        $this->dispatch('toast', message: __('Yedek alınıyor.'));
    }

    public function openSchedule(): void
    {
        $app = $this->app();
        $this->schedule = $app->backup_schedule;
        $this->time = $app->backup_time;
        $this->keep = $app->backup_keep;
        $this->resetValidation();
        $this->scheduling = true;
    }

    public function saveSchedule(): void
    {
        $this->validate([
            'schedule' => ['required', Rule::in(['off', 'daily', 'weekly'])],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'keep' => ['required', 'integer', 'between:1,90'],
        ], ['time.regex' => __('Saati SS:DD biçiminde yazın, örneğin 03:00.')], ['keep' => __('saklanacak yedek sayısı'), 'time' => 'saat']);
        $app = $this->app();
        $before = $app->only(['backup_schedule', 'backup_time', 'backup_keep']);
        $app->update(['backup_schedule' => $this->schedule, 'backup_time' => $this->time, 'backup_keep' => $this->keep]);
        $this->scheduling = false;
        $this->dispatch('toast', message: __('Yedekleme planı kaydedildi.'), source: $this->getId(), undo: ['method' => 'restoreSchedule', 'args' => [$before]]);
    }

    /**
     * @param  array<string, mixed>  $before
     */
    public function restoreSchedule(array $before): void
    {
        $this->app()->update(array_intersect_key($before, array_flip(['backup_schedule', 'backup_time', 'backup_keep'])));
    }

    public function openRestore(int $id): void
    {
        $backup = $this->backup($id);
        if ($backup === null || $backup->status !== 'succeeded') {
            return;
        }
        $this->restoreId = $backup->id;
        $this->restoreParts = $this->restorable($backup);
        $this->safety = true;
        $this->resetValidation();
        $this->restoring = true;
    }

    public function restore(BackupService $backups): void
    {
        $backup = $this->restoreId ? $this->backup($this->restoreId) : null;
        if ($backup === null || $backup->status !== 'succeeded' || $this->restoreRunning()) {
            $this->restoring = false;

            return;
        }
        $this->validate(['restoreParts' => ['required', 'array', 'min:1'], 'restoreParts.*' => [Rule::in($this->restorable($backup))]],
            ['restoreParts.required' => __('En az bir parça seçin.'), 'restoreParts.min' => __('En az bir parça seçin.')]);
        $backups->restore($backup, array_values($this->restoreParts), $this->safety, auth()->user());
        $this->restoring = false;
        $this->dispatch('toast', message: __('Geri yükleme başladı.'));
    }

    /**
     * After a restore: put back the backup taken just before it.
     */
    public function undoRestore(int $id, BackupService $backups): void
    {
        $restored = $this->backup($id);
        $before = $restored ? $this->safetyBackup($restored) : null;
        if ($before === null || $this->restoreRunning()) {
            return;
        }
        $backups->restore($before, $before->parts, false, auth()->user());
        $this->dispatch('toast', message: __('Geri yüklemeden önceki hâline dönülüyor.'));
    }

    public function delete(int $id, BackupService $backups): void
    {
        $backup = $this->backup($id);
        if ($backup === null || $backup->isRunning() || in_array($backup->restore_status, ['queued', 'running'], true)) {
            return;
        }
        if ($backup->status === 'failed') {
            $backups->purge($backup);

            return;
        }
        $backups->delete($backup);
        $this->dispatch('toast',
            message: __('Yedek silindi.'),
            source: $this->getId(),
            undo: ['method' => 'undoDelete', 'args' => [$backup->id]],
        );
    }

    public function undoDelete(int $id, BackupService $backups): void
    {
        $backup = $this->backup($id);
        if ($backup !== null && $backups->undoDelete($backup)) {
            $this->dispatch('toast', message: __('Yedek geri getirildi.'));
        }
    }

    public function showLog(int $id, bool $restore = false): void
    {
        $backup = $this->backup($id);
        $run = $restore ? $backup?->restore_run : $backup?->run;
        if ($run === null) {
            return;
        }
        $this->logRun = $run;
        $this->logTitle = $restore ? __('Geri yükleme günlüğü') : __('Yedekleme günlüğü');
        $this->viewingLog = true;
    }

    public function closeLog(): void
    {
        $this->reset('viewingLog', 'logRun', 'logTitle');
    }

    /**
     * Parts of $backup that can be put back: code only for applications
     * Serfex deploys.
     *
     * @return list<string>
     */
    private function restorable(LaravelBackup $backup): array
    {
        $parts = array_values(array_intersect(array_keys(LaravelBackup::PARTS), $backup->parts ?? []));

        return $this->app()->isManaged() ? $parts : array_values(array_diff($parts, ['code']));
    }

    private function restoreRunning(): bool
    {
        return LaravelBackup::where('laravel_app_id', $this->appId)->whereIn('restore_status', ['queued', 'running'])->exists();
    }

    /**
     * The backup the restore from $backup took first, if any.
     */
    private function safetyBackup(LaravelBackup $backup): ?LaravelBackup
    {
        if ($backup->restored_at === null) {
            return null;
        }

        return LaravelBackup::where('laravel_app_id', $this->appId)->where('kind', 'restore')->where('status', 'succeeded')
            ->whereBetween('created_at', [$backup->restored_at->copy()->subHours(3), $backup->restored_at])
            ->latest('id')->first();
    }

    private function backup(int $id): ?LaravelBackup
    {
        return LaravelBackup::where('laravel_app_id', $this->appId)->find($id);
    }

    public function render(): View
    {
        $app = $this->app();
        $backups = $app->backups()->where('status', '!=', 'deleted')->limit(100)->get();
        $restore = LaravelBackup::where('laravel_app_id', $app->id)->whereNotNull('restore_status')->latest('updated_at')->first();
        if ($restore !== null && in_array($restore->restore_status, ['succeeded', 'failed'], true) && $restore->restored_at?->lt(now()->subMinutes(30))) {
            $restore = null;
        }

        return view('livewire.laravel.sections.backups', [
            'app' => $app,
            'backups' => $backups,
            'restore' => $restore,
            'canUndoRestore' => $restore?->restore_status === 'succeeded' && $this->safetyBackup($restore) !== null,
            'busy' => $backups->contains(fn (LaravelBackup $b) => $b->isRunning()) || in_array($restore?->restore_status, ['queued', 'running'], true),
            'manage' => auth()->user()->canOnApp($app, 'manage'),
            'restoreBackup' => $this->restoreId ? $this->backup($this->restoreId) : null,
        ]);
    }
}
