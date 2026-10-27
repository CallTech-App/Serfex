<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Security\Fail2ban;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every minute: copies Fail2ban's bans into the
 * panel's IP block list (Canlı İzleme → Saldırılar).
 */
#[Signature('serfex:fail2ban-sync')]
#[Description('Copy Fail2ban bans into the IP block list')]
class SyncFail2banBans extends Command
{
    public function handle(Fail2ban $fail2ban): int
    {
        try {
            $n = $fail2ban->sync();
        } catch (AgentException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$n} Fail2ban bans.");

        return self::SUCCESS;
    }
}
