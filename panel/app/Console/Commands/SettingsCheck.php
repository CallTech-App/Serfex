<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Settings\PanelSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler: a panel port change nobody
 * confirmed from the new port in time goes back to the old port.
 */
#[Signature('servik:settings-check')]
#[Description('Revert an unconfirmed panel port change')]
class SettingsCheck extends Command
{
    public function handle(PanelSettings $settings): int
    {
        if (! $settings->portChangeExpired()) {
            return self::SUCCESS;
        }
        try {
            $settings->revertPort();
        } catch (AgentException $e) {
            $this->error('Reverting the port failed: '.$e->reason);

            return self::FAILURE;
        }
        $this->info('The port change was not confirmed; back to port '.$settings->port().'.');

        return self::SUCCESS;
    }
}
