<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('servik:ssl-renew')->hourly()->withoutOverlapping();
Schedule::command('servik:mail-usage')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('servik:disk-usage')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('servik:laravel-backups')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('servik:components-check')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('servik:monitor-record')->everyMinute()->withoutOverlapping();
Schedule::command('servik:backups')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('servik:update-check')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('servik:files-trash')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('servik:security-scan')->weeklyOn(0, '03:20')->withoutOverlapping();
Schedule::command('servik:fail2ban-sync')->everyMinute()->withoutOverlapping();
Schedule::command('servik:services-check')->everyMinute()->withoutOverlapping();
Schedule::command('servik:notify')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('servik:settings-check')->everyMinute()->withoutOverlapping();
Schedule::command('servik:cron-history')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('servik:stats-collect')->everyMinute()->withoutOverlapping();
Schedule::command('servik:stats-rollup')->hourlyAt(3)->withoutOverlapping();
Schedule::command('servik:stats-prune')->dailyAt('04:10')->withoutOverlapping();
