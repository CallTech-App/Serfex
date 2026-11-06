<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Cloudflare\CloudflareRanges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler once a week: fetches the addresses Cloudflare's
 * proxy connects from and gives them to the agent, which trusts them for
 * the visitor's real address and never blocks them.
 */
#[Signature('serfex:cloudflare-ips')]
#[Description('Update the list of Cloudflare proxy addresses')]
class UpdateCloudflareRanges extends Command
{
    public function handle(CloudflareRanges $ranges): int
    {
        try {
            $n = $ranges->update();
        } catch (AgentException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$n} Cloudflare ranges.");

        return self::SUCCESS;
    }
}
