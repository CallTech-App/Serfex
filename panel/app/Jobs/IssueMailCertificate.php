<?php

namespace App\Jobs;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailSettings;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Obtains (or renews) the Let's Encrypt certificate of the mail server's
 * name, used by SMTP, IMAP, POP3 and webmail. The first time, the mail
 * server is set up again to switch from its self-signed certificate;
 * renewals reload it from the agent.
 */
class IssueMailCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public Certificate $certificate) {}

    public function uniqueId(): string
    {
        return 'mail';
    }

    public function handle(AgentClient $agent, DnsCheck $dns, MailSettings $settings): void
    {
        $certificate = Certificate::find($this->certificate->id);
        $hostname = $settings->hostname();
        if ($certificate === null || $hostname === null) {
            return;
        }
        $check = $dns->check($hostname);
        if (! $check['ok']) {
            $where = $check['found'] === [] ? __('hiçbir adrese') : __(':found adresine', ['found' => implode(', ', $check['found'])]);
            $this->markFailed($certificate, CertificateStatus::WaitingDns, __('DNS henüz bu sunucuya işaret etmiyor: :hostname şu an :where çözülüyor. A kaydını bu sunucuya yönlendirin; Serfex her saat yeniden dener.', ['hostname' => $hostname, 'where' => $where]));

            return;
        }

        try {
            $result = $agent->call('ssl.issue', array_filter([
                'name' => MailSettings::CERTIFICATE,
                'names' => [$hostname],
                'challenge' => 'http-01',
                'email' => Certificates::email(),
            ]), config('serfex.agent.ssl_timeout'));
        } catch (AgentException $e) {
            [$reason, $retryAt] = Certificates::failure($e);
            $this->markFailed($certificate, CertificateStatus::Failed, $reason, $retryAt);

            return;
        }
        $certificate->recordInstalled($result);

        if ($settings->isSetUp() && ($settings->server()['certificate'] ?? '') !== $settings->certificateName()) {
            SetupMailServer::dispatch();
        }
    }

    private function markFailed(Certificate $certificate, CertificateStatus $status, string $message, ?Carbon $retryAt = null): void
    {
        if ($certificate->isInstalled() && ! $certificate->isExpired() && $certificate->domains === [$this->hostname()]) {
            $certificate->update(['status' => CertificateStatus::Active, 'renew_error' => $message, 'attempted_at' => now()]);

            return;
        }
        $certificate->update(['status' => $status, 'last_error' => $message, 'attempted_at' => now(), 'retry_at' => $retryAt]);
    }

    private function hostname(): ?string
    {
        return app(MailSettings::class)->hostname();
    }
}
