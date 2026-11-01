<?php

namespace App\Services\Tuning;

use App\Models\TuningChange;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Services\System\SystemServices;
use App\Support\Bytes;
use Illuminate\Support\Facades\Log;

/**
 * Applies optimization suggestions to the database server and watches
 * them. A change goes through the agent's settings (written to Serfex's
 * tuning file and set on the running server, no restart). For the next
 * WATCH_MINUTES the server is checked every minute; if it stopped, or the
 * machine started swapping or ran out of memory, the old values come back
 * by themselves. An applied change can be undone for UNDO_DAYS.
 */
class Tuner
{
    public const WATCH_MINUTES = 30;

    public const UNDO_DAYS = 7;

    /** Swap growth that counts as the machine running out of memory. */
    public const SWAP_GROWTH_MB = 256;

    /** Available memory below this share of RAM rolls a change back. */
    public const MIN_AVAILABLE = 0.03;

    public function __construct(
        private readonly SystemServices $services,
        private readonly Samples $samples,
        private readonly TuningSettings $settings,
    ) {}

    /**
     * Applies a suggestion now, or schedules it for the maintenance
     * window. Returns the change, or why not. Its title and labels are
     * stored in the form that reads in every language (Turkish keys,
     * "mb:4096").
     *
     * @param  array<string, mixed>  $suggestion  from MysqlAdvisor
     */
    public function apply(string $unit, array $suggestion, bool $now, ?User $user): TuningChange|string
    {
        if ($this->active($unit, $suggestion['key']) !== null) {
            return __('Bu ayar için bekleyen bir değişiklik var.');
        }
        $current = $this->services->settings($unit, 'database');
        if (is_string($current)) {
            return $current;
        }
        $change = TuningChange::create([
            'unit' => $unit,
            'key' => $suggestion['key'],
            'title' => $suggestion['title_key'] ?? $suggestion['title'],
            'from_values' => array_intersect_key($current, $suggestion['values']),
            'to_values' => $suggestion['values'],
            'from_label' => $suggestion['from_raw'] ?? $suggestion['from_label'],
            'to_label' => $suggestion['to_raw'] ?? $suggestion['to_label'],
            'status' => 'scheduled',
            'scheduled_for' => $now ? now() : $this->settings->nextWindow(now()),
            'user_id' => $user?->id,
        ]);
        if ($now) {
            $error = $this->execute($change);
            if ($error !== null) {
                return $error;
            }
        }

        return $change->fresh();
    }

    /**
     * Puts a change in force and starts watching it; returns why not.
     */
    public function execute(TuningChange $change): ?string
    {
        $result = $this->set($change->unit, $change->to_values);
        if (is_string($result)) {
            $change->update(['status' => 'failed', 'finished_at' => now(), 'note' => $result]);

            return $result;
        }
        $change->update([
            'status' => 'watching',
            'applied_at' => now(),
            'watch_until' => now()->addMinutes(self::WATCH_MINUTES),
            'baseline' => $this->memory($change->unit),
        ]);

        return null;
    }

    /**
     * Runs every minute: scheduled changes in the maintenance window and
     * the checks of the ones being watched.
     */
    public function tick(): void
    {
        if ($this->settings->inWindow(now())) {
            foreach (TuningChange::where('status', 'scheduled')->where('scheduled_for', '<=', now())->get() as $change) {
                $this->execute($change);
            }
        }
        foreach (TuningChange::where('status', 'watching')->get() as $change) {
            $this->check($change);
        }
    }

    public function check(TuningChange $change): void
    {
        $service = $this->services->find($change->unit);
        if ($service !== null && ! in_array($service['state'] ?? '', ['active', 'reloading'], true)) {
            $this->rollBack($change, __(':name değişiklikten sonra durdu.', ['name' => $service['name'] ?? $change->unit]));

            return;
        }
        $now = $this->memory($change->unit);
        $base = (array) $change->baseline;
        if ($now !== null && $base !== []) {
            $grown = $now['swap_used_mb'] - (int) ($base['swap_used_mb'] ?? 0);
            if ($grown > self::SWAP_GROWTH_MB) {
                $this->rollBack($change, __('Değişiklikten sonra sunucu takas kullanmaya başladı (:size).', ['size' => Bytes::format($grown << 20)]));

                return;
            }
            if ($now['memory_mb'] > 0 && $now['available_mb'] < self::MIN_AVAILABLE * $now['memory_mb']) {
                $this->rollBack($change, __('Değişiklikten sonra sunucunun boş belleği tükenmek üzereydi.'));

                return;
            }
        }
        if ($change->watch_until !== null && $change->watch_until->isPast()) {
            $change->update(['status' => 'applied']);
        }
    }

    /**
     * Puts the old values back by itself and tells the administrators.
     */
    public function rollBack(TuningChange $change, string $reason): void
    {
        $result = $this->set($change->unit, $change->from_values);
        $change->update([
            'status' => 'rolled_back',
            'finished_at' => now(),
            'note' => is_string($result) ? $reason.' '.__('Eski değer geri yüklenemedi: :error', ['error' => $result]) : $reason,
        ]);
        Log::warning('Tuning change rolled back', ['unit' => $change->unit, 'key' => $change->key, 'reason' => $reason]);
    }

    public function canUndo(TuningChange $change): bool
    {
        return in_array($change->status, TuningChange::IN_FORCE, true) && $change->applied_at?->gt(now()->subDays(self::UNDO_DAYS));
    }

    /**
     * Undoes an applied change; returns why not.
     */
    public function undo(TuningChange $change): ?string
    {
        if (! $this->canUndo($change)) {
            return __('Bu değişiklik artık geri alınamaz.');
        }
        $result = $this->set($change->unit, $change->from_values);
        if (is_string($result)) {
            return $result;
        }
        $change->update(['status' => 'undone', 'finished_at' => now()]);

        return null;
    }

    public function cancel(TuningChange $change): void
    {
        if ($change->status === 'scheduled') {
            $change->update(['status' => 'cancelled', 'finished_at' => now()]);
        }
    }

    /**
     * Hides a suggestion for a week.
     *
     * @param  array<string, mixed>  $suggestion
     */
    public function dismiss(string $unit, array $suggestion, ?User $user): void
    {
        TuningChange::create([
            'unit' => $unit, 'key' => $suggestion['key'], 'title' => $suggestion['title_key'] ?? $suggestion['title'],
            'from_values' => [], 'to_values' => $suggestion['values'], 'from_label' => $suggestion['from_raw'] ?? $suggestion['from_label'], 'to_label' => $suggestion['to_raw'] ?? $suggestion['to_label'],
            'status' => 'dismissed', 'finished_at' => now(), 'user_id' => $user?->id,
        ]);
    }

    /**
     * The change of a setting that is scheduled or being watched.
     */
    public function active(string $unit, string $key): ?TuningChange
    {
        return TuningChange::where('unit', $unit)->where('key', $key)->whereIn('status', ['scheduled', 'watching'])->latest('id')->first();
    }

    /**
     * Sets database settings, keeping every other one as it is.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|string
     */
    private function set(string $unit, array $values): array|string
    {
        $current = $this->services->settings($unit, 'database');
        if (is_string($current)) {
            return $current;
        }

        return $this->services->applySettings($unit, 'database', array_merge($current, $values));
    }

    /**
     * The machine's memory now, from a fresh measurement.
     *
     * @return array{memory_mb: int, available_mb: int, swap_used_mb: int}|null
     */
    private function memory(string $unit): ?array
    {
        try {
            $data = $this->samples->record($unit)->data;
        } catch (AgentException) {
            return null;
        }

        return [
            'memory_mb' => (int) ($data['memory_mb'] ?? 0),
            'available_mb' => (int) ($data['available_mb'] ?? 0),
            'swap_used_mb' => (int) ($data['swap_used_mb'] ?? 0),
        ];
    }
}
