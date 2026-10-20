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
            self::Installing => __('Kuruluyor'),
            self::Active => __('Yayında'),
            self::Failed => __('Kurulamadı'),
            self::Removing => __('Kaldırılıyor'),
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
