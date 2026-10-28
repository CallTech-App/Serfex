<?php

namespace App\Enums;

enum WordPressOperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // The update broke the site and was put back automatically.
    case RolledBack = 'rolled_back';
    // The update finished, but something in it failed (one plugin).
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Sırada'),
            self::Running => __('Sürüyor'),
            self::Succeeded => __('Başarılı'),
            self::Failed => __('Başarısız'),
            self::RolledBack => __('Geri döndürüldü'),
            self::Partial => __('Kısmen başarılı'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'info',
            self::Succeeded => 'ok',
            self::Failed => 'crit',
            self::RolledBack, self::Partial => 'warn',
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
