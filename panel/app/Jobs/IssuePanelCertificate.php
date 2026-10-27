<?php

namespace App\Jobs;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Obtains (or renews) the Let's Encrypt certificate of the panel's own
 * address. The agent keeps it apart from customer certificates and hands
 * it to the panel's own web server.
 */
class IssuePanelCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public Certificate $certificate) {}

    public function uniqueId(): string
    {
        return 'panel';
    }

    public function handle(AgentClient $agent, DnsCheck $dns): void
    {
        $certificate = Certificate::find($this->certificate->id);
        if ($certificate === null) {
            return;
        }
        // The address set now, which may have changed since the last renewal.
        $hostname = Setting::get('panel.hostname') ?: ($certificate->domains[0] ?? null);
        if ($hostname === null) {
            return;
        }
        $check = $dns->check($hostname);
        if (! $check['ok']) {
            $where = $check['found'] === [] ? __('hiçbir adrese') : __(':found adresine', ['found' => implode(', ', $check['found'])]);
            $this->markFailed($certificate, CertificateStatus::WaitingDns, __('DNS henüz bu sunucuya işaret etmiyor: :hostname şu an :where çözülüyor. A kaydını bu sunucuya yönlendirin; Serfex her saat yeniden dener.', ['hostname' => $hostname, 'where' => $where]));

            return;
        }

        try {
            $result = $agent->call('ssl.panel.issue', array_filter([
                'hostname' => $hostname,
                'email' => Certificates::email(),
            ]), config('serfex.agent.ssl_timeout'));
        } catch (AgentException $e) {
            [$reason, $retryAt] = Certificates::failure($e);
            $this->markFailed($certificate, CertificateStatus::Failed, $reason, $retryAt);

            return;
        }
        $certificate->recordInstalled($result);
    }

    private function markFailed(Certificate $certificate, CertificateStatus $status, string $message, ?Carbon $retryAt = null): void
    {
        if ($certificate->isInstalled() && ! $certificate->isExpired()) {
            $certificate->update(['status' => CertificateStatus::Active, 'renew_error' => $message, 'attempted_at' => now()]);

            return;
        }
        $certificate->update(['status' => $status, 'last_error' => $message, 'attempted_at' => now(), 'retry_at' => $retryAt]);
    }
}
