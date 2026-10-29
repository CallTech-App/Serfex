<?php

namespace App\Console\Commands;

use App\Jobs\ApplySitePhp;
use App\Models\Setting;
use App\Models\Site;
use App\Services\System\SystemServices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every minute: reads which system services are
 * stopped or failed, for Genel Bakış and the menu. The first run after an
 * update also writes customers' PHP-FPM pools again, so sites created
 * before PHP settings existed get the disabled functions, and the sites
 * of Laravel applications get proc_open back (0.1.50).
 */
#[Signature('serfex:services-check')]
#[Description('Record stopped or failed system services')]
class CheckServices extends Command
{
    public function handle(SystemServices $services): int
    {
        $error = null;
        if ($services->list($error) === null) {
            $this->warn($error ?? __('Sunucu ajanına ulaşılamıyor.'));

            return self::FAILURE;
        }
        if (! Setting::get('php.pools_synced', false)) {
            Setting::put('php.pools_synced', true);
            ApplySitePhp::forCustomers();
        }
        // 0.1.50: Laravel applications' sites keep proc_open for sendmail.
        if (! Setting::get('php.laravel_pools_synced', false)) {
            Setting::put('php.laravel_pools_synced', true);
            $ids = Site::whereHas('laravelApp')->whereHas('systemUser', fn ($u) => $u->whereNotNull('customer_id'))->pluck('id')->all();
            if ($ids !== []) {
                ApplySitePhp::dispatch($ids);
            }
        }
        $n = count(SystemServices::problems());
        $this->info($n === 0 ? __('Tüm hizmetler çalışıyor.') : __(':n hizmet durmuş.', ['n' => $n]));

        return self::SUCCESS;
    }
}
