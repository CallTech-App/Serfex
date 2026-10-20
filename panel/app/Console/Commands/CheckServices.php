<?php

namespace App\Console\Commands;

use App\Jobs\ApplySitePhp;
use App\Models\Setting;
use App\Services\System\SystemServices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler every minute: reads which system services are
 * stopped or failed, for Genel Bakış and the menu. The first run after an
 * update also writes customers' PHP-FPM pools again, so sites created
 * before PHP settings existed get the disabled functions.
 */
#[Signature('servik:services-check')]
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
        $n = count(SystemServices::problems());
        $this->info($n === 0 ? __('Tüm hizmetler çalışıyor.') : __(':n hizmet durmuş.', ['n' => $n]));

        return self::SUCCESS;
    }
}
