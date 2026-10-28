<?php

namespace App\Services\Backups;

use App\Models\BackupTarget;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The server-wide backup schedule and the passphrase remote copies are
 * encrypted with (stored encrypted with the panel's application key).
 */
class BackupSettings
{
    public const FREQUENCIES = ['daily' => 'Her gün', 'weekly' => 'Haftada bir', 'monthly' => 'Ayda bir'];

    public const WEEKDAYS = [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 0 => 'Pazar'];

    public const DEFAULTS = [
        'enabled' => true,
        'frequency' => 'daily',
        'time' => '03:00',
        // Daily: the day of the full backup (incrementals on the others).
        // Weekly: the day it runs.
        'weekday' => 0,
        'incremental' => true,
        'keep_daily' => 7,
        'keep_weekly' => 4,
        'keep_monthly' => 3,
        // null: the server's own disk.
        'target_id' => null,
        // With a remote target, keep the newest copy on this server too.
        'keep_local' => false,
        'encrypt' => true,
        // tar patterns left out of site files, such as */cache/*.
        'exclude' => ['*/storage/framework/cache/*', '*/node_modules/*'],
        // Domains left out of the server backup.
        'skip_sites' => [],
        // Days the backup taken before a site is deleted is kept.
        'delete_days' => 30,
    ];

    /**
     * @return array<string, mixed>
     */
    public function schedule(): array
    {
        $saved = Setting::get('backups.schedule');

        return array_replace(self::DEFAULTS, is_array($saved) ? array_intersect_key($saved, self::DEFAULTS) : []);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        Setting::put('backups.schedule', array_replace($this->schedule(), array_intersect_key($values, self::DEFAULTS)));
    }

    public function target(): ?BackupTarget
    {
        $id = $this->schedule()['target_id'];

        return $id ? BackupTarget::find($id) : null;
    }

    /**
     * The passphrase remote copies are encrypted with, made on first use.
     */
    public function passphrase(): string
    {
        $stored = Setting::get('backups.passphrase');
        if (is_string($stored) && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                // A panel restored with another application key: a new
                // passphrase for new backups; old ones need the old one.
            }
        }
        $passphrase = implode('-', str_split(Str::lower(Str::password(32, symbols: false, spaces: false)), 8));
        $this->setPassphrase($passphrase);

        return $passphrase;
    }

    public function setPassphrase(string $passphrase): void
    {
        Setting::put('backups.passphrase', Crypt::encryptString($passphrase));
    }

    /**
     * When the next scheduled backup runs, or null when scheduling is off.
     */
    public function next(Carbon $now): ?Carbon
    {
        $s = $this->schedule();
        if (! $s['enabled']) {
            return null;
        }
        [$h, $m] = $this->time($s['time']);
        // The time is the panel's (Araçlar ve Ayarlar → Genel).
        $local = $now->copy()->timezone(config('serfex.timezone'));
        for ($i = 0; $i < 62; $i++) {
            $at = $local->copy()->addDays($i)->setTime($h, $m);
            if ($at->gt($now) && $this->runsOn($at, $s)) {
                return $at->utc();
            }
        }

        return null;
    }

    /**
     * The scheduled time on or before $now that the latest backup should
     * have run at.
     */
    public function lastSlot(Carbon $now): ?Carbon
    {
        $s = $this->schedule();
        [$h, $m] = $this->time($s['time']);
        $local = $now->copy()->timezone(config('serfex.timezone'));
        for ($i = 0; $i < 62; $i++) {
            $at = $local->copy()->subDays($i)->setTime($h, $m);
            if ($at->lte($now) && $this->runsOn($at, $s)) {
                return $at->utc();
            }
        }

        return null;
    }

    /**
     * Whether the backup at $at is a full one (else incremental).
     */
    public function fullOn(Carbon $at): bool
    {
        $s = $this->schedule();
        $at = $at->copy()->timezone(config('serfex.timezone'));

        return match (true) {
            ! $s['incremental'], $s['frequency'] === 'monthly' => true,
            $s['frequency'] === 'daily' => $at->dayOfWeek === (int) $s['weekday'],
            // Weekly: the first of the month's backups is full.
            default => $at->day <= 7,
        };
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function runsOn(Carbon $at, array $s): bool
    {
        return match ($s['frequency']) {
            'weekly' => $at->dayOfWeek === (int) $s['weekday'],
            'monthly' => $at->day === 1,
            default => true,
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function time(string $time): array
    {
        [$h, $m] = array_map('intval', explode(':', $time) + [1 => 0]);

        return [min(23, max(0, $h)), min(59, max(0, $m))];
    }

    /**
     * "Her gün 03:00 (pazar tam, diğer günler artımlı)".
     */
    public function summary(): string
    {
        $s = $this->schedule();
        if (! $s['enabled']) {
            return __('Otomatik yedekleme kapalı');
        }
        $day = mb_strtolower(__(self::WEEKDAYS[(int) $s['weekday']] ?? __('Pazar')));

        return match ($s['frequency']) {
            'weekly' => $s['incremental'] ? __('Her :day :time (ayın ilki tam, diğerleri artımlı)', ['day' => $day, 'time' => $s['time']]) : __('Her :day :time, tam', ['day' => $day, 'time' => $s['time']]),
            'monthly' => __('Her ayın 1\'i :time, tam', ['time' => $s['time']]),
            default => $s['incremental'] ? __('Her gün :time (:day tam, diğer günler artımlı)', ['day' => $day, 'time' => $s['time']]) : __('Her gün :time, tam', ['time' => $s['time']]),
        };
    }
}
