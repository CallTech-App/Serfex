<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('servik:ssl-renew')->hourly()->withoutOverlapping();
Schedule::command('servik:mail-usage')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('servik:laravel-backups')->everyFiveMinutes()->withoutOverlapping();
