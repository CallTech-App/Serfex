<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Hazırlanıyor',
            self::Active => 'Yayında',
            self::Failed => 'Hata',
            self::Deleting => 'Siliniyor',
        };
    }

    /**
     * Design token for the status colour: ok, warn, crit or info.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Provisioning, self::Deleting => 'info',
            self::Active => 'ok',
            self::Failed => 'crit',
        };
    }

    /**
     * True while the agent is still working on the site.
     */
    public function isBusy(): bool
    {
        return $this === self::Provisioning || $this === self::Deleting;
    }
}
