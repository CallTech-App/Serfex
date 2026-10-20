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
            self::Provisioning => __('Hazırlanıyor'),
            self::Active => __('Yayında'),
            self::Failed => __('Hata'),
            self::Deleting => __('Siliniyor'),
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
