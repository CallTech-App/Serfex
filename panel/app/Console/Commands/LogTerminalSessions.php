<?php

namespace App\Console\Commands;

use App\Services\Files\WebTerminal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every minute: logs the end of SSH terminali
 * sessions whose page never said so (the tab was closed, the computer
 * slept), from the agent's list of ended sessions.
 */
#[Signature('serfex:terminal-sessions')]
#[Description('Log the end of browser terminal sessions')]
class LogTerminalSessions extends Command
{
    public function handle(WebTerminal $terminal): int
    {
        $this->info('Logged '.$terminal->sweep().' ended terminal sessions.');

        return self::SUCCESS;
    }
}
