<?php

namespace App\Jobs;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Obtains (or renews) a Let's Encrypt certificate through the agent and
 * switches the site to it.
 *
 * For HTTP validation every name is first looked up in public DNS. Names
 * that do not point at this server yet are left out (www, aliases); when
 * the domain itself does not, nothing is sent to Let's Encrypt and the
 * certificate waits for DNS, retried by serfex:ssl-renew. That keeps a
 * domain whose DNS lives elsewhere from using up Let's Encrypt's limit on
 * failed validations. A name behind Cloudflare's proxy counts as pointing
 * here (DnsCheck).
 */
class IssueCertificate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    /**
     * @param  bool  $auto  requested by Serfex itself (new site, retry, renewal)
     * @param  string|null  $zone  DNS zone on this server, for dns-01
     */
    public function __construct(public Certificate $certificate, public bool $auto = false, public ?string $zone = null) {}

    public function uniqueId(): string
    {
        return (string) $this->certificate->id;
    }

    public function handle(AgentClient $agent, DnsCheck $dns, Certificates $certificates): void
    {
        $certificate = Certificate::with('site')->find($this->certificate->id);
        if ($certificate === null || $certificate->site === null) {
            return;
        }
        $site = $certificate->site;
        $names = $certificate->domains;

        if ($certificate->challenge !== 'dns-01') {
            // Automatic runs cover every name the site answers to now, so a
            // www that starts pointing here later is picked up on renewal.
            if ($this->auto) {
                $names = $site->hostnames();
            }
            $pointing = [];
            $missing = [];
            foreach ($names as $name) {
                $result = $dns->check($name);
                if ($result['ok']) {
                    $pointing[] = $name;
                } else {
                    $missing[$name] = $result['found'];
                }
            }
            if (! in_array($site->domain, $pointing, true)) {
                $this->markFailed($certificate, CertificateStatus::WaitingDns, $this->dnsMessage($site->domain, $missing[$site->domain] ?? [], $dns));

                return;
            }
            $names = $pointing;
        }

        try {
            $result = $agent->call('ssl.issue', array_filter([
                'name' => $certificate->name,
                'names' => $names,
                'challenge' => $certificate->challenge ?? 'http-01',
                'dns_zone' => $this->zone,
                'email' => Certificates::email(),
            ]), config('serfex.agent.ssl_timeout'));
        } catch (AgentException $e) {
            [$reason, $retryAt] = Certificates::failure($e);
            $this->markFailed($certificate, CertificateStatus::Failed, $reason, $retryAt);

            return;
        }

        $certificate->recordInstalled($result);
        if ($site->fresh()->certificate_id !== $certificate->id) {
            $certificates->use($site, $certificate);
        }
    }

    /**
     * Record a failure. A certificate that is installed keeps working, so a
     * failed renewal is kept apart from a request that never succeeded.
     */
    private function markFailed(Certificate $certificate, CertificateStatus $status, string $message, ?Carbon $retryAt = null): void
    {
        if ($certificate->isInstalled() && ! $certificate->isExpired()) {
            $certificate->update(['status' => CertificateStatus::Active, 'renew_error' => $message, 'attempted_at' => now()]);

            return;
        }
        $certificate->update(['status' => $status, 'last_error' => $message, 'attempted_at' => now(), 'retry_at' => $retryAt]);
    }

    /**
     * @param  list<string>  $found
     */
    private function dnsMessage(string $domain, array $found, DnsCheck $dns): string
    {
        $here = implode(', ', $dns->serverAddresses()) ?: 'bilinmiyor';
        $there = $found === [] ? __('hiçbir adrese') : __(':found adresine', ['found' => implode(', ', $found)]);

        return __('DNS henüz bu sunucuya işaret etmiyor: :domain şu an :there çözülüyor (bu sunucu: :here). Alan adının A kaydını bu sunucuya yönlendirin; Serfex her saat yeniden dener.', ['domain' => $domain, 'there' => $there, 'here' => $here]);
    }
}
