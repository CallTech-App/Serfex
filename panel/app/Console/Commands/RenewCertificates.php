<?php

namespace App\Console\Commands;

use App\Enums\CertificateStatus;
use App\Jobs\IssueMailCertificate;
use App\Jobs\IssuePanelCertificate;
use App\Models\Certificate;
use App\Models\Site;
use App\Services\Sites\SiteDomains;
use App\Services\Ssl\Certificates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Runs hourly from the scheduler: renews Let's Encrypt certificates that
 * expire within servik.ssl.renew_days, retries the ones waiting for DNS,
 * retries failed requests once a day, and reissues site certificates
 * when names they lack now point here.
 */
#[Signature('servik:ssl-renew')]
#[Description("Renew Let's Encrypt certificates and retry pending ones")]
class RenewCertificates extends Command
{
    public function handle(Certificates $certificates, SiteDomains $domains): int
    {
        $due = Certificate::query()
            ->where('source', Certificate::LETS_ENCRYPT)
            ->where('status', '!=', CertificateStatus::Issuing)
            ->where(fn (Builder $q) => $q
                // Waiting for DNS: every hour.
                ->where(fn (Builder $q) => $q->where('status', CertificateStatus::WaitingDns)
                    ->where(fn (Builder $q) => $q->whereNull('attempted_at')->orWhere('attempted_at', '<=', now()->subMinutes(55))))
                // Failed requests: once a day.
                ->orWhere(fn (Builder $q) => $q->where('status', CertificateStatus::Failed)
                    ->where('attempted_at', '<=', now()->subDay()->addMinutes(5)))
                // Expiring: when due, then twice a day while renewals fail.
                ->orWhere(fn (Builder $q) => $q->where('status', CertificateStatus::Active)
                    ->where('auto_renew', true)
                    ->where('not_after', '<=', now()->addDays((int) config('servik.ssl.renew_days')))
                    ->where(fn (Builder $q) => $q->whereNull('attempted_at')->orWhere('attempted_at', '<=', now()->subHours(12)))))
            ->with('site', 'users')
            ->get();

        $count = 0;
        foreach ($due as $certificate) {
            if ($certificate->kind === 'panel') {
                $certificate->update(['status' => CertificateStatus::Issuing]);
                IssuePanelCertificate::dispatch($certificate);
            } elseif ($certificate->kind === 'mail') {
                $certificate->update(['status' => CertificateStatus::Issuing]);
                IssueMailCertificate::dispatch($certificate);
            } elseif ($certificate->site !== null && ($certificate->users->isNotEmpty() || ! $certificate->isInstalled())) {
                $certificates->reissue($certificate, auto: true);
            } else {
                continue;
            }
            $count++;
        }
        // Names added to a site (aliases, subdomains, redirected domains)
        // whose DNS points here now join its certificate.
        $sites = Site::whereHas('certificate', fn (Builder $q) => $q
            ->where('source', Certificate::LETS_ENCRYPT)
            ->where('status', CertificateStatus::Active)
            ->where('auto_renew', true)
            ->where(fn (Builder $q) => $q->whereNull('attempted_at')->orWhere('attempted_at', '<=', now()->subMinutes(55))))
            ->with('certificate', 'certificates', 'subdomains', 'redirects')
            ->get();
        foreach ($sites as $site) {
            if ($site->certificate->renew_error !== null && $site->certificate->attempted_at?->gt(now()->subDay())) {
                continue;
            }
            if ($domains->extendCertificate($site)) {
                $count++;
            }
        }
        $this->info(__(':count sertifika kuyruğa alındı.', ['count' => $count]));

        return self::SUCCESS;
    }
}
