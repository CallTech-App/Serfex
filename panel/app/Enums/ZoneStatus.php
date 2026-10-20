<?php

namespace App\Enums;

enum ZoneStatus: string
{
    case Syncing = 'syncing';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Syncing => __('Uygulanıyor'),
            self::Active => __('Bu sunucuda'),
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
            self::Syncing, self::Deleting => 'info',
            self::Active => 'ok',
            self::Failed => 'crit',
        };
    }

    /**
     * True while the agent is still working on the zone.
     */
    public function isBusy(): bool
    {
        return $this === self::Syncing || $this === self::Deleting;
    }
}
