<?php

namespace App\Enums;

enum MailDomainStatus: string
{
    case Syncing = 'syncing';
    case Active = 'active';
    case Failed = 'failed';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Syncing => __('Uygulanıyor'),
            self::Active => __('Posta açık'),
            self::Failed => __('Hata'),
            self::Deleting => __('Kapatılıyor'),
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
     * True while the agent is still working on the domain.
     */
    public function isBusy(): bool
    {
        return $this === self::Syncing || $this === self::Deleting;
    }
}
