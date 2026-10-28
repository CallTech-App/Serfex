<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('serfex:ssl-renew')->hourly()->withoutOverlapping();
Schedule::command('serfex:mail-usage')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('serfex:disk-usage')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('serfex:disk-quota')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('serfex:laravel-backups')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('serfex:components-check')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('serfex:monitor-record')->everyMinute()->withoutOverlapping();
Schedule::command('serfex:backups')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('serfex:update-check')->dailyAt('04:30')->withoutOverlapping();
Schedule::command('serfex:files-trash')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('serfex:security-scan')->weeklyOn(0, '03:20')->withoutOverlapping();
Schedule::command('serfex:fail2ban-sync')->everyMinute()->withoutOverlapping();
Schedule::command('serfex:services-check')->everyMinute()->withoutOverlapping();
Schedule::command('serfex:notify')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('serfex:settings-check')->everyMinute()->withoutOverlapping();
Schedule::command('serfex:cron-history')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('serfex:stats-collect')->everyMinute()->withoutOverlapping();
Schedule::command('serfex:stats-rollup')->hourlyAt(3)->withoutOverlapping();
Schedule::command('serfex:stats-prune')->dailyAt('04:10')->withoutOverlapping();
