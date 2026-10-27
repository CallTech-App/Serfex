<?php

namespace App\Console\Commands;

use App\Services\Settings\Notifications;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every five minutes from the scheduler: tells the administrators
 * about new problems and the ones that went away (Bildirimler).
 */
#[Signature('serfex:notify')]
#[Description('E-mail and post new and resolved problems to the administrators')]
class SendNotifications extends Command
{
    public function handle(Notifications $notifications): int
    {
        $result = $notifications->check();
        $this->info("{$result['opened']} new, {$result['resolved']} resolved.");

        return self::SUCCESS;
    }
}
