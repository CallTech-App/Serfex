<?php

namespace App\Livewire\Services;

use App\Models\TuningChange;
use App\Services\Agent\AgentException;
use App\Services\System\SystemServices;
use App\Services\Tuning\AiNotes;
use App\Services\Tuning\Optimization;
use App\Services\Tuning\Samples;
use App\Services\Tuning\Tuner;
use App\Services\Tuning\TuningSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Hizmetler → MySQL (or MariaDB) → Öneriler: what the last week's
 * measurements say, the suggestions with Apply, Apply tonight and
 * Dismiss, the changes being watched or rolled back, the settings that
 * are fine and the slow statements.
 */
class DatabaseTuning extends Component
{
    #[Locked]
    public string $unit;

    /** The suggestion whose Apply dialog is open. */
    public ?string $confirming = null;

    /** now or window */
    public string $when = 'now';

    public ?string $error = null;

    public function mount(string $unit): void
    {
        abort_unless(in_array($unit, Optimization::UNITS, true), 404);
        $this->unit = $unit;
    }

    public function measure(Samples $samples, Optimization $optimization): void
    {
        try {
            $samples->record($this->unit);
        } catch (AgentException $e) {
            $this->error = SystemServices::message($e);

            return;
        }
        $this->error = null;
        $optimization->refreshAi($this->unit);
        $this->dispatch('toast', message: __('Ölçüldü.'));
    }

    public function confirm(string $key, TuningSettings $settings): void
    {
        $this->confirming = $key;
        $this->when = $settings->level() === TuningSettings::WINDOW ? 'window' : 'now';
    }

    public function apply(Optimization $optimization, Tuner $tuner, TuningSettings $settings): void
    {
        $key = $this->confirming;
        $this->confirming = null;
        $suggestion = $key ? $optimization->suggestion($this->unit, $key) : null;
        if ($suggestion === null || $suggestion['blocked'] !== null || $settings->isLocked($this->unit, $key)) {
            $this->error = __('Bu öneri artık geçerli değil; sayfa yenilendi.');

            return;
        }
        $now = $this->when === 'now' && $settings->level() !== TuningSettings::WINDOW;
        $result = $tuner->apply($this->unit, $suggestion, $now, auth()->user());
        if (is_string($result)) {
            $this->error = __('Uygulanamadı, önceki değer duruyor: :error', ['error' => $result]);

            return;
        }
        $this->error = null;
        $this->dispatch('toast', message: $now
            ? __(':title: uygulandı, :minutes dakika gözlenecek.', ['title' => $result->title, 'minutes' => Tuner::WATCH_MINUTES])
            : __(':title: bakım penceresinde uygulanacak.', ['title' => $result->title]));
    }

    public function applyTonight(string $key, TuningSettings $settings, Optimization $optimization, Tuner $tuner): void
    {
        $this->confirming = $key;
        $this->when = 'window';
        $this->apply($optimization, $tuner, $settings);
    }

    public function dismiss(string $key, Optimization $optimization, Tuner $tuner): void
    {
        if ($suggestion = $optimization->suggestion($this->unit, $key)) {
            $tuner->dismiss($this->unit, $suggestion, auth()->user());
            $this->dispatch('toast', message: __('Öneri 7 gün gösterilmeyecek.'));
        }
    }

    public function cancel(int $id, Tuner $tuner): void
    {
        $tuner->cancel(TuningChange::where('unit', $this->unit)->findOrFail($id));
        $this->dispatch('toast', message: __('Planlanan değişiklik iptal edildi.'));
    }

    public function undo(int $id, Tuner $tuner): void
    {
        $error = $tuner->undo(TuningChange::where('unit', $this->unit)->findOrFail($id));
        if ($error !== null) {
            $this->error = $error;

            return;
        }
        $this->dispatch('toast', message: __('Önceki değer geri yüklendi.'));
    }

    public function render(Optimization $optimization, TuningSettings $settings, Tuner $tuner): View
    {
        Optimization::forgetCount($this->unit);
        $level = $settings->level();
        $report = $level === TuningSettings::OFF ? null : $optimization->report($this->unit);
        $confirm = null;
        if ($this->confirming && $report) {
            $confirm = collect($report['cards'])->first(fn ($c) => $c['key'] === $this->confirming && $c['state'] === 'new');
        }

        return view('livewire.services.database-tuning', [
            'level' => $level,
            'report' => $report,
            'confirm' => $confirm,
            'aiOn' => app(AiNotes::class)->available(),
            'window' => $settings->window(),
            'nextWindow' => $settings->nextWindow(now()),
            'tuner' => $tuner,
        ]);
    }
}
