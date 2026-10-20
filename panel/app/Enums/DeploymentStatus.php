<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // The health check failed and the previous release is live again.
    case RolledBack = 'rolled_back';
    // The health check failed and the new release stayed live.
    case Unhealthy = 'unhealthy';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Sırada'),
            self::Running => __('Sürüyor'),
            self::Succeeded => __('Başarılı'),
            self::Failed => __('Başarısız'),
            self::RolledBack => __('Sağlık kontrolü başarısız, geri dönüldü'),
            self::Unhealthy => __('Yayında, sağlık kontrolü başarısız'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'info',
            self::Succeeded => 'ok',
            self::Failed, self::RolledBack => 'crit',
            self::Unhealthy => 'warn',
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * True when the deploy's release went live and stayed live.
     */
    public function isLive(): bool
    {
        return $this === self::Succeeded || $this === self::Unhealthy;
    }
}
