<?php

namespace App\Jobs;

use App\Enums\CertificateStatus;
use App\Enums\MailDomainStatus;
use App\Models\Certificate;
use App\Models\MailDomain;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\WebmailAddresses;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Obtains (or renews) the Let's Encrypt certificate of a mail domain's
 * webmail address (webmail.<domain>) by HTTP validation on the mail
 * server's vhost, then has the agent serve the address with it.
 */
class IssueWebmailCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public Certificate $certificate, public MailDomain $domain) {}

    public function uniqueId(): string
    {
        return 'webmail-'.$this->certificate->id;
    }

    public function handle(AgentClient $agent, DnsCheck $dns, WebmailAddresses $addresses): void
    {
        $certificate = Certificate::find($this->certificate->id);
        $domain = MailDomain::find($this->domain->id);
        if ($certificate === null) {
            return;
        }
        if ($domain === null || $domain->status === MailDomainStatus::Deleting || ! $addresses->enabled()) {
            // Turned off (or the domain went) meanwhile: an installed
            // certificate stays as it was, a new request is dropped.
            $certificate->isInstalled() ? $certificate->update(['status' => CertificateStatus::Active]) : $certificate->delete();

            return;
        }
        $name = WebmailAddresses::name($domain);
        $check = $dns->check($name);
        if (! $check['ok']) {
            $where = $check['found'] === [] ? __('hiçbir adrese') : __(':found adresine', ['found' => implode(', ', $check['found'])]);
            $this->markFailed($certificate, $name, CertificateStatus::WaitingDns, __('DNS henüz bu sunucuya işaret etmiyor: :hostname şu an :where çözülüyor. A kaydını bu sunucuya yönlendirin; Serfex her saat yeniden dener.', ['hostname' => $name, 'where' => $where]));

            return;
        }

        try {
            $result = $agent->call('ssl.issue', array_filter([
                'name' => $certificate->name,
                'names' => [$name],
                'challenge' => 'http-01',
                'email' => Certificates::email(),
            ]), config('serfex.agent.ssl_timeout'));
        } catch (AgentException $e) {
            [$reason, $retryAt] = Certificates::failure($e);
            $this->markFailed($certificate, $name, CertificateStatus::Failed, $reason, $retryAt);

            return;
        }
        $certificate->recordInstalled($result);
        SyncWebmailAddresses::dispatch();
    }

    private function markFailed(Certificate $certificate, string $name, CertificateStatus $status, string $message, ?Carbon $retryAt = null): void
    {
        // A renewal that fails keeps the certificate in use while it lasts.
        if ($certificate->isInstalled() && ! $certificate->isExpired() && $certificate->domains === [$name]) {
            $certificate->update(['status' => CertificateStatus::Active, 'renew_error' => $message, 'attempted_at' => now()]);

            return;
        }
        $certificate->update(['status' => $status, 'last_error' => $message, 'attempted_at' => now(), 'retry_at' => $retryAt]);
    }
}
