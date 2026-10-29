<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Mail\MailSenders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every hour: sends which domains each site's
 * system user may send mail as, in case a change's own call failed.
 */
#[Signature('serfex:mail-senders')]
#[Description('Limit the senders of mail from each site to its own domains')]
class LimitMailSenders extends Command
{
    public function handle(MailSenders $senders): int
    {
        try {
            $sent = $senders->sync();
        } catch (AgentException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }
        $this->info($sent ? 'Mail sender limits applied.' : 'The mail server is not set up.');

        return self::SUCCESS;
    }
}
