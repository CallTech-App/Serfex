<?php

namespace App\Services\Tuning;

use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * How service optimization works on this server (Hizmetler →
 * Optimizasyon): the level, the maintenance window, the share of memory
 * the services may take and the settings a person locked.
 */
class TuningSettings
{
    /** Nothing is measured or suggested. */
    public const OFF = 'off';

    /** Suggestions are shown; nothing changes until a person applies one. */
    public const SUGGEST = 'suggest';

    /** What a person applies waits for the maintenance window. */
    public const WINDOW = 'window';

    public const LEVELS = [self::OFF, self::SUGGEST, self::WINDOW];

    public const MEMORY_MIN = 50;

    public const MEMORY_MAX = 90;

    public function level(): string
    {
        $level = (string) Setting::get('tuning.level', self::SUGGEST);

        return in_array($level, self::LEVELS, true) ? $level : self::SUGGEST;
    }

    public function enabled(): bool
    {
        return $this->level() !== self::OFF;
    }

    /**
     * The maintenance window in server time, as "HH:MM".
     *
     * @return array{0: string, 1: string}
     */
    public function window(): array
    {
        return [(string) Setting::get('tuning.window_start', '03:00'), (string) Setting::get('tuning.window_end', '05:00')];
    }

    /**
     * Share of the machine's memory the services may take together, in
     * percent; the rest is left to the operating system's file cache.
     */
    public function memoryPercent(): int
    {
        return max(self::MEMORY_MIN, min(self::MEMORY_MAX, (int) Setting::get('tuning.memory_percent', 80)));
    }

    public function save(string $level, string $start, string $end, int $memoryPercent): void
    {
        Setting::put('tuning.level', $level);
        Setting::put('tuning.window_start', $start);
        Setting::put('tuning.window_end', $end);
        Setting::put('tuning.memory_percent', $memoryPercent);
    }

    /**
     * Whether $at falls in the maintenance window (which may run past
     * midnight).
     */
    public function inWindow(CarbonInterface $at): bool
    {
        [$start, $end] = $this->window();
        $time = CarbonImmutable::instance($at)->setTimezone(config('serfex.timezone'))->format('H:i');

        return $start <= $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    /**
     * When the next maintenance window starts after $at (or $at itself
     * when it is in one).
     */
    public function nextWindow(CarbonInterface $at): CarbonImmutable
    {
        $local = CarbonImmutable::instance($at)->setTimezone(config('serfex.timezone'));
        if ($this->inWindow($at)) {
            return $local->utc();
        }
        [$h, $m] = array_map('intval', explode(':', $this->window()[0]));
        $start = $local->setTime($h, $m);
        if ($start <= $local) {
            $start = $start->addDay();
        }

        return $start->utc();
    }

    /**
     * Suggestion keys a person locked: key => [who, when (ISO 8601)].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public function locked(string $unit): array
    {
        return (array) (Setting::get('tuning.locked', [])[$unit] ?? []);
    }

    public function isLocked(string $unit, string $key): bool
    {
        return isset($this->locked($unit)[$key]);
    }

    public function lock(string $unit, string $key, ?User $user): void
    {
        $all = (array) Setting::get('tuning.locked', []);
        $all[$unit][$key] = [$user->name ?? 'Serfex', now()->toIso8601String()];
        Setting::put('tuning.locked', $all);
    }

    public function unlock(string $unit, string $key): void
    {
        $all = (array) Setting::get('tuning.locked', []);
        unset($all[$unit][$key]);
        Setting::put('tuning.locked', $all);
    }
}
