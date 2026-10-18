<?php

namespace App\Enums;

enum LaravelAppStatus: string
{
    case Installing = 'installing';
    case Active = 'active';
    case Failed = 'failed';
    case Removing = 'removing';

    public function label(): string
    {
        return match ($this) {
            self::Installing => 'Kuruluyor',
            self::Active => 'Yayında',
            self::Failed => 'Kurulamadı',
            self::Removing => 'Kaldırılıyor',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Installing, self::Removing => 'info',
            self::Active => 'ok',
            self::Failed => 'crit',
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Installing || $this === self::Removing;
    }
}
