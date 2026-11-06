<?php

namespace App\Console\Commands;

use App\Services\Sites\Uptime;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('serfex:uptime {--prune : Forget checks and outages older than 90 days}')]
#[Description('Open every site as a visitor would and tell the administrators when one stops answering')]
class CheckUptime extends Command
{
    public function handle(Uptime $uptime): int
    {
        if ($this->option('prune')) {
            $this->info(__(':count kayıt silindi.', ['count' => $uptime->prune()]));

            return self::SUCCESS;
        }
        $r = $uptime->check();
        $this->info(__(':checked site denetlendi, :down site çöktü, :up site düzeldi.', $r));

        return self::SUCCESS;
    }
}
