<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Audit\AuditLog;
use App\Services\Monitor\MonitorException;
use App\Services\Security\CountryBlocks;
use App\Support\Countries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Lifts every country block from the server's console: `sudo serfex
 * firewall countries off`, for an administrator who is in a blocked
 * country and cannot reach the panel. The scope is kept for the next
 * block; addresses blocked one by one are not touched.
 */
#[Signature('serfex:countries-off {--json : Print the result as JSON (serfex firewall countries off)}')]
#[Description('Lift the block on every blocked country')]
class CountriesOff extends Command
{
    public function handle(CountryBlocks $countries): int
    {
        try {
            $lifted = $countries->off();
        } catch (AgentException|MonitorException $e) {
            $message = __('Ülke engelleri kaldırılamadı: :reason', ['reason' => $e instanceof AgentException ? $e->forPeople() : $e->getMessage()]);
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }
        $names = array_map(Countries::name(...), $lifted);
        if ($lifted !== []) {
            AuditLog::record(trans_choice(':count ülkenin engeli sunucu konsolundan kaldırıldı: :countries.', count($lifted), ['count' => count($lifted), 'countries' => implode(', ', $names)]), null, 'system',
                ['actor' => __('Sunucu konsolu'), 'section' => __('Güvenlik'), 'url' => route('security.blocks', ['tab' => 'countries'], false)]);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['lifted' => $lifted, 'names' => $names], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if ($lifted === []) {
            $this->components->info(__('Engelli ülke yok.'));

            return self::SUCCESS;
        }
        $this->components->info(trans_choice(':count ülkenin engeli kaldırıldı.', count($lifted), ['count' => count($lifted)]));
        $this->line('  '.implode(', ', $names));

        return self::SUCCESS;
    }
}
