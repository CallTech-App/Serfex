<?php

namespace App\Jobs;

use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailServerDns;
use App\Services\Mail\MailSettings;
use App\Services\Ssl\Certificates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Installs (or reconfigures) the mail server through the agent with the
 * current settings, then applies every domain that waited for it and asks
 * for the server name's certificate when it has none.
 *
 * Progress is kept in the "mail.server" setting: state installing, ready or
 * failed, with the error, the webmail address and the certificate in use.
 */
class SetupMailServer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Installing packages can take a while on a slow mirror. */
    public int $timeout = 900;

    public function handle(AgentClient $agent, MailSettings $settings, Certificates $certificates, MailServerDns $serverDns): void
    {
        $hostname = $settings->hostname();
        if ($hostname === null) {
            $settings->saveServer(['state' => 'failed', 'error' => __('Önce E-posta ayarlarından posta sunucusunun adını girin.')]);

            return;
        }
        $settings->saveServer(['state' => 'installing', 'error' => null]);
        $certificate = $settings->certificateName();

        try {
            $result = $agent->call('mail.setup', [
                'hostname' => $hostname,
                'certificate' => $certificate,
                'hourly_limit' => $settings->hourlyLimit(),
                'webmail' => $settings->webmail(),
                'webmail_app' => $settings->webmailApp(),
            ], 840);
        } catch (AgentException $e) {
            $settings->saveServer(['state' => 'failed', 'error' => $e->reason]);

            return;
        }

        $settings->saveServer([
            'state' => 'ready',
            'error' => null,
            'hostname' => $result['hostname'] ?? $hostname,
            'certificate' => $certificate,
            'webmail_url' => $result['webmail_url'] ?? null,
            'webmail_app' => $result['webmail_app'] ?? null,
            'webmail_version' => $result['webmail_version'] ?? null,
            // This setup installed the newest SnappyMail the agent has.
            'webmail_latest' => null,
            'setup_at' => now()->toIso8601String(),
        ]);

        $serverDns->apply($hostname);
        SyncMailSenders::dispatch();

        MailDomain::whereIn('status', [MailDomainStatus::Failed, MailDomainStatus::Syncing])->each(function (MailDomain $domain) {
            if ($domain->status === MailDomainStatus::Failed) {
                $domain->update(['status' => MailDomainStatus::Syncing, 'last_error' => null]);
            }
            SyncMailDomain::dispatch($domain);
        });
        // The records of every domain follow a new server name.
        MailDomain::where('status', MailDomainStatus::Active)->where('manage_dns', true)->each(fn (MailDomain $d) => SyncMailDomain::dispatch($d));

        if ($settings->needsCertificate()) {
            $certificates->issueMail($hostname);
        }
    }
}
