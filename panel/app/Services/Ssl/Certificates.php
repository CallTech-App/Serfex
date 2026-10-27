<?php

namespace App\Services\Ssl;

use App\Enums\CertificateStatus;
use App\Enums\SiteStatus;
use App\Enums\ZoneStatus;
use App\Jobs\DeleteCertificate;
use App\Jobs\IssueCertificate;
use App\Jobs\IssueMailCertificate;
use App\Jobs\IssuePanelCertificate;
use App\Jobs\ProvisionSite;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\Zones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Requests, installs and swaps site certificates, and queues the agent work
 * that follows.
 */
class Certificates
{
    /** How long a replaced certificate is kept so its swap can be undone. */
    public const UNDO_SECONDS = 600;

    public function __construct(private readonly Zones $zones) {}

    /**
     * Ask Let's Encrypt for a certificate covering the site's names (and
     * every subdomain with $wildcard). The site's current Let's Encrypt
     * certificate is reissued in place; otherwise a new one is added and
     * the site switches to it once it is issued.
     */
    public function letsEncrypt(Site $site, bool $wildcard = false, bool $auto = false): Certificate
    {
        $names = $site->hostnames();
        $zone = null;
        if ($wildcard) {
            $zone = $this->zoneFor($site->domain)
                ?? throw new \InvalidArgumentException("{$site->domain} is not hosted on this server's DNS; wildcards need DNS validation.");
            $names = [$site->domain, '*.'.$site->domain];
        }

        // Reuse the current Let's Encrypt certificate, or a request that is
        // still waiting, before adding a new one.
        $current = $site->certificate;
        $certificate = match (true) {
            $current !== null && $current->isLetsEncrypt() => $current,
            default => $site->certificates()->whereNull('not_after')->where('source', Certificate::LETS_ENCRYPT)->latest('id')->first()
                ?? new Certificate([
                    'site_id' => $site->id,
                    'name' => $this->freeName($site->domain),
                    'source' => Certificate::LETS_ENCRYPT,
                ]),
        };
        $certificate->fill([
            'domains' => $names,
            'challenge' => $wildcard ? 'dns-01' : 'http-01',
            'status' => CertificateStatus::Issuing,
            'last_error' => null,
        ])->save();

        IssueCertificate::dispatch($certificate, auto: $auto, zone: $zone?->name);

        return $certificate;
    }

    /**
     * Why a request through the agent failed, for people, and when to try
     * again early: a request that never reached Let's Encrypt (this
     * server's network or DNS was down for a moment) is retried within the
     * hour instead of the next day.
     *
     * @return array{0: string, 1: Carbon|null}
     */
    public static function failure(AgentException $e): array
    {
        if ($e->getCode() !== AgentException::CA_UNREACHABLE) {
            return [$e->reason, null];
        }

        return [
            __("Let's Encrypt'e ulaşılamadı: bu sunucunun ağ bağlantısı ya da DNS çözümlemesi o an çalışmıyordu. Servik bir saat içinde yeniden dener. Ayrıntı: :reason", ['reason' => $e->reason]),
            now()->addMinutes(5),
        ];
    }

    /**
     * Retry or renew a Let's Encrypt certificate with the names it has.
     */
    public function reissue(Certificate $certificate, bool $auto = false): void
    {
        $zone = $certificate->challenge === 'dns-01' ? $this->zoneFor($certificate->site->domain) : null;
        $certificate->update(['status' => CertificateStatus::Issuing, 'last_error' => null]);
        IssueCertificate::dispatch($certificate, auto: $auto, zone: $zone?->name);
    }

    /**
     * Store an uploaded certificate on the server and switch the site to
     * it. The agent checks it first; its refusal comes back as an
     * AgentException with a readable reason.
     *
     * @throws AgentException
     */
    public function upload(Site $site, AgentClient $agent, string $certificate, string $key, string $chain): Certificate
    {
        $name = $this->freeName($site->domain);
        $info = $agent->call('ssl.install', [
            'name' => $name,
            'certificate' => $certificate,
            'private_key' => $key,
            'chain' => $chain,
            'domain' => $site->domain,
        ], config('servik.agent.job_timeout'));

        $uploaded = Certificate::create([
            'site_id' => $site->id,
            'name' => $name,
            'source' => Certificate::CUSTOM,
            'challenge' => null,
            'auto_renew' => false,
            'status' => CertificateStatus::Active,
        ]);
        $uploaded->recordInstalled($info);
        $this->use($site, $uploaded);

        return $uploaded;
    }

    /**
     * Make the site serve $certificate (or plain HTTP with null) and queue
     * the vhost change. The certificate it used before is removed from the
     * server after UNDO_SECONDS unless a site takes it back.
     */
    public function use(Site $site, ?Certificate $certificate): void
    {
        $previous = $site->certificate_id;
        if ($previous === $certificate?->id) {
            return;
        }
        $site->update(['certificate_id' => $certificate?->id, 'status' => SiteStatus::Provisioning, 'last_error' => null]);
        $site->unsetRelation('certificate');
        // Requests that never produced a certificate are moot now.
        $site->certificates()->whereNull('not_after')
            ->whereIn('status', [CertificateStatus::WaitingDns, CertificateStatus::Failed])
            ->delete();
        ProvisionSite::dispatch($site);
        $old = $previous === null ? null : Certificate::find($previous);
        if ($old !== null) {
            DeleteCertificate::dispatch($old)->delay(now()->addSeconds(self::UNDO_SECONDS));
        }
    }

    /**
     * Turn the HTTP to HTTPS redirect on or off.
     */
    public function setRedirect(Site $site, bool $redirect): void
    {
        $site->update(['https_redirect' => $redirect]);
        if ($site->hasHttps()) {
            $site->update(['status' => SiteStatus::Provisioning, 'last_error' => null]);
            ProvisionSite::dispatch($site);
        }
    }

    /**
     * Request certificates for a site that was just created, when the
     * administrator left automatic SSL on.
     */
    public function autoIssue(Site $site): void
    {
        if (! Setting::get('ssl.auto', true) || $site->certificate_id !== null || $site->certificates()->exists()) {
            return;
        }
        $this->letsEncrypt($site, auto: true);
    }

    /**
     * Request (or renew) the Let's Encrypt certificate of the panel's own
     * address, kept apart from customer certificates.
     */
    public function issuePanel(string $hostname): Certificate
    {
        $certificate = Certificate::firstOrNew(['kind' => 'panel'], [
            'name' => 'panel',
            'source' => Certificate::LETS_ENCRYPT,
            'challenge' => 'http-01',
        ]);
        if (! $certificate->isInstalled()) {
            $certificate->domains = [$hostname];
        }
        // An uploaded certificate is replaced by Let's Encrypt's from here on.
        $certificate->fill(['source' => Certificate::LETS_ENCRYPT, 'challenge' => 'http-01', 'auto_renew' => true, 'status' => CertificateStatus::Issuing, 'last_error' => null])->save();
        IssuePanelCertificate::dispatch($certificate);

        return $certificate;
    }

    /**
     * Request (or renew) the certificate of the mail server's name, shared
     * by SMTP, IMAP, POP3 and webmail and kept apart from site certificates.
     */
    public function issueMail(string $hostname): Certificate
    {
        $certificate = Certificate::firstOrNew(['kind' => 'mail'], [
            'name' => 'mail',
            'source' => Certificate::LETS_ENCRYPT,
            'challenge' => 'http-01',
        ]);
        if (! $certificate->isInstalled()) {
            $certificate->domains = [$hostname];
        }
        $certificate->fill(['status' => CertificateStatus::Issuing, 'last_error' => null])->save();
        IssueMailCertificate::dispatch($certificate);

        return $certificate;
    }

    /**
     * The zone on this server that holds $domain, for DNS-01 validation:
     * its own zone or a parent's. Null when DNS is hosted elsewhere.
     */
    public function zoneFor(string $domain): ?DnsZone
    {
        $zone = DnsZone::where('name', $domain)->first() ?? $this->zones->parentZone($domain);

        return $zone !== null && $zone->status !== ZoneStatus::Deleting ? $zone : null;
    }

    /**
     * Address for expiry notices from Let's Encrypt.
     */
    public static function email(): ?string
    {
        return Setting::get('ssl.email') ?: User::query()->orderBy('id')->value('email');
    }

    /**
     * A certificate name not taken yet: the domain, then domain-2, ...
     */
    private function freeName(string $domain): string
    {
        return DB::transaction(function () use ($domain) {
            $taken = Certificate::where('name', $domain)->orWhere('name', 'like', $domain.'-%')->pluck('name')->all();
            $name = $domain;
            for ($i = 2; in_array($name, $taken, true); $i++) {
                $name = $domain.'-'.$i;
            }

            return $name;
        });
    }
}
