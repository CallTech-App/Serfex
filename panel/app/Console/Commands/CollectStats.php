<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Stats\StatsCollector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs every minute: stores what the agent counted from the access and
 * mail logs since the last run.
 */
#[Signature('serfex:stats-collect')]
#[Description('Store the traffic and mail statistics the agent counted')]
class CollectStats extends Command
{
    public function handle(StatsCollector $collector): int
    {
        try {
            $result = $collector->collect();
        } catch (AgentException $e) {
            $this->warn('Agent unavailable: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($result['id'] !== '') {
            $this->info(__(':hours site saati kaydedildi (:id).', ['hours' => $result['hours'], 'id' => $result['id']]));
        }

        return self::SUCCESS;
    }
}
