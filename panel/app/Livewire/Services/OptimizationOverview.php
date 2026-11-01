<?php

namespace App\Livewire\Services;

use App\Services\Tuning\AiNotes;
use App\Services\Tuning\Optimization;
use App\Services\Tuning\TuningSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Hizmetler → Optimizasyon: the memory budget the services share, the
 * servers optimization looks at with their suggestions, and how it works
 * here (level, maintenance window, memory limit).
 */
class OptimizationOverview extends Component
{
    public string $level = TuningSettings::SUGGEST;

    public string $windowStart = '03:00';

    public string $windowEnd = '05:00';

    public string $memoryPercent = '80';

    public function mount(TuningSettings $settings): void
    {
        $this->level = $settings->level();
        [$this->windowStart, $this->windowEnd] = $settings->window();
        $this->memoryPercent = (string) $settings->memoryPercent();
    }

    public function save(TuningSettings $settings): void
    {
        abort_unless(auth()->user()->is_admin && ! auth()->user()->isReadOnly(), 403);
        $this->validate([
            'level' => ['required', 'in:'.implode(',', TuningSettings::LEVELS)],
            'windowStart' => ['required', 'date_format:H:i'],
            'windowEnd' => ['required', 'date_format:H:i', 'different:windowStart'],
            'memoryPercent' => ['required', 'integer', 'between:'.TuningSettings::MEMORY_MIN.','.TuningSettings::MEMORY_MAX],
        ], [], [
            'windowStart' => __('pencere başlangıcı'), 'windowEnd' => __('pencere bitişi'), 'memoryPercent' => __('bellek sınırı'),
        ]);
        $settings->save($this->level, $this->windowStart, $this->windowEnd, (int) $this->memoryPercent);
        foreach (Optimization::UNITS as $unit) {
            Optimization::forgetCount($unit);
        }
        $this->dispatch('toast', message: __('Optimizasyon ayarları kaydedildi.'));
    }

    public function render(Optimization $optimization, TuningSettings $settings): View
    {
        $servers = [];
        $budget = null;
        foreach ($optimization->servers() as $s) {
            $report = $settings->enabled() ? $optimization->report($s['unit']) : null;
            $servers[] = ['unit' => $s['unit'], 'name' => $s['name'], 'report' => $report];
            $budget ??= $report['budget'] ?? null;
        }

        return view('livewire.services.optimization-overview', [
            'servers' => $servers,
            'budget' => $budget,
            'aiOn' => app(AiNotes::class)->available(),
            'enabled' => $settings->enabled(),
        ]);
    }
}
