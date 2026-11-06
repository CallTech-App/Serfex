<?php

namespace App\Console\Commands;

use App\Services\Laravel\BackgroundWatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('serfex:laravel-watch')]
#[Description("Read how each Laravel application's queue workers, processes and scheduled tasks are doing")]
class WatchLaravel extends Command
{
    public function handle(BackgroundWatch $watch): int
    {
        $this->info(__(':count uygulama okundu.', ['count' => $watch->check()]));

        return self::SUCCESS;
    }
}
