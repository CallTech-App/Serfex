<?php

namespace App\Console\Commands;

use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use App\Services\Mail\MailUsage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: records how much mail each mailbox stores.
 */
#[Signature('serfex:mail-usage')]
#[Description('Record the disk usage of every mailbox')]
class RefreshMailUsage extends Command
{
    public function handle(MailUsage $usage): int
    {
        $count = 0;
        MailDomain::where('status', MailDomainStatus::Active)->each(function (MailDomain $domain) use ($usage, &$count) {
            $count += (int) $usage->refresh($domain);
        });
        $this->info(__(':count alan adının posta kullanımı güncellendi.', ['count' => $count]));

        return self::SUCCESS;
    }
}
