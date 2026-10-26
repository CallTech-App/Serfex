<?php

namespace App\Enums;

enum GitDeploymentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // The files went live but the post-deploy commands failed.
    case CommandsFailed = 'commands_failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => __('Sırada'),
            self::Running => __('Sürüyor'),
            self::Succeeded => __('Başarılı'),
            self::Failed => __('Başarısız'),
            self::CommandsFailed => __('Yayında, komut başarısız'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued, self::Running => 'info',
            self::Succeeded => 'ok',
            self::Failed => 'crit',
            self::CommandsFailed => 'warn',
        };
    }

    public function isBusy(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * True when the deploy's files went live.
     */
    public function isLive(): bool
    {
        return $this === self::Succeeded || $this === self::CommandsFailed;
    }
}
