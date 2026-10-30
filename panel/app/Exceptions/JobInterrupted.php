<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A queued job whose worker stopped while running it (see RunningJobs).
 */
class JobInterrupted extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('Panelin iş kuyruğu bu iş sürerken durduruldu (panel güncellemesi ya da yeniden başlatma).'));
    }
}
