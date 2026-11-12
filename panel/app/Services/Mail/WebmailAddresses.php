<?php

namespace App\Services\Mail;

use App\Enums\CertificateStatus;
use App\Enums\MailDomainStatus;
use App\Jobs\IssueWebmailCertificate;
use App\Jobs\SyncWebmailAddresses;
use App\Models\Certificate;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Support\Badge;

/**
 * Each mail domain's own webmail address, webmail.<domain>: its DNS record
 * (MailDns), its Let's Encrypt certificate (kind "webmail", one per
 * domain) and the agent serving it (mail.webmail.hosts) once the
 * certificate is in place. Mailbox users sign in there with their address
 * and password, without the panel; the mail server's own address and the
 * panel's one-click sign-in stay as they are.
 */
class WebmailAddresses
{
    public const KIND = 'webmail';

    /** The addresses the agent serves now (mail.webmail.hosts). */
    private const SERVED = 'mail.webmail_hosts';

    public function __construct(private readonly MailSettings $settings) {}

    /**
     * Whether mail domains get their own address: the setting is on and
     * webmail is served.
     */
    public function enabled(): bool
    {
        return $this->settings->domainWebmail() && $this->settings->webmail() && $this->settings->hostname() !== null;
    }

    public static function name(MailDomain $domain): string
    {
        return 'webmail.'.$domain->name;
    }

    public static function url(MailDomain $domain): string
    {
        return 'https://'.self::name($domain).'/';
    }

    /**
     * Name of the domain's certificate in the agent's store.
     */
    public static function certificateName(MailDomain $domain): string
    {
        return 'webmail-'.$domain->name;
    }

    public function certificate(MailDomain $domain): ?Certificate
    {
        return Certificate::where('kind', self::KIND)->where('name', self::certificateName($domain))->first();
    }

    /**
     * Ask for the domain's certificate when it has none (or it covers
     * another name), unless one is being requested.
     */
    public function request(MailDomain $domain, bool $force = false): ?Certificate
    {
        if (! $this->enabled() || $domain->status === MailDomainStatus::Deleting) {
            return null;
        }
        $certificate = Certificate::firstOrNew(['kind' => self::KIND, 'name' => self::certificateName($domain)], [
            'source' => Certificate::LETS_ENCRYPT,
            'challenge' => 'http-01',
        ]);
        $name = self::name($domain);
        if ($certificate->exists && ! $force && ($certificate->status === CertificateStatus::Issuing
            || ($certificate->domains === [$name] && $certificate->status !== CertificateStatus::Failed))) {
            return $certificate;
        }
        if (! $certificate->isInstalled()) {
            $certificate->domains = [$name];
        }
        $certificate->fill(['status' => CertificateStatus::Issuing, 'last_error' => null])->save();
        IssueWebmailCertificate::dispatch($certificate, $domain);

        return $certificate;
    }

    /**
     * Whether the agent serves the domain's address now.
     */
    public function served(MailDomain $domain): bool
    {
        return in_array(self::name($domain), array_column($this->servedHosts(), 'name'), true);
    }

    /**
     * Where the domain's address stands: null when there is none; ready,
     * dns (waiting for the record to point here), cert (certificate being
     * requested) or failed (with the error).
     *
     * @return array{url: string, state: string, error: ?string, badge: Badge}|null
     */
    public function state(MailDomain $domain): ?array
    {
        if (! $this->enabled() || $domain->status === MailDomainStatus::Deleting) {
            return null;
        }
        $certificate = $this->certificate($domain);
        $state = match (true) {
            $this->served($domain) => 'ready',
            $certificate?->status === CertificateStatus::WaitingDns => 'dns',
            $certificate?->status === CertificateStatus::Failed => 'failed',
            default => 'cert',
        };

        return [
            'url' => self::url($domain),
            'state' => $state,
            'error' => $state === 'failed' ? $certificate?->last_error : null,
            'badge' => match ($state) {
                'ready' => new Badge(__('Hazır'), 'ok'),
                'dns' => new Badge(__('DNS bekleniyor'), 'warn'),
                'failed' => new Badge(__('Sertifika alınamadı'), 'crit'),
                default => new Badge(__('Sertifika alınıyor'), 'info'),
            },
        ];
    }

    /**
     * The address to give a mailbox user: the domain's own when it is
     * served, otherwise the mail server's.
     */
    public function urlFor(?MailDomain $domain): ?string
    {
        if ($domain !== null && $this->enabled() && $domain->status !== MailDomainStatus::Deleting && $this->served($domain)) {
            return self::url($domain);
        }

        return $this->settings->webmailUrl();
    }

    /**
     * Bring the server in line: request missing certificates and have the
     * agent serve every domain whose certificate is in place, and no
     * others. Returns whether the served addresses changed.
     */
    public function sync(AgentClient $agent): bool
    {
        if (! $this->settings->isSetUp()) {
            return false;
        }
        $hosts = [];
        if ($this->enabled()) {
            $domains = MailDomain::where('status', '!=', MailDomainStatus::Deleting)->orderBy('name')->get();
            $certificates = Certificate::where('kind', self::KIND)->get()->keyBy('name');
            foreach ($domains as $domain) {
                $certificate = $certificates->get(self::certificateName($domain));
                if ($certificate === null) {
                    $this->request($domain);

                    continue;
                }
                if ($certificate->isInstalled() && ! $certificate->isExpired() && in_array(self::name($domain), $certificate->domains, true)) {
                    $hosts[] = ['name' => self::name($domain), 'certificate' => $certificate->name];
                }
            }
        }
        if ($hosts === $this->servedHosts()) {
            return false;
        }
        $agent->call('mail.webmail.hosts', ['hosts' => $hosts], config('serfex.agent.job_timeout'));
        Setting::put(self::SERVED, $hosts);

        return true;
    }

    /**
     * The setting was turned on or off: write (or remove) the records of
     * every domain whose DNS Serfex manages, then serve (or stop serving)
     * the addresses. Turning it on again (Undo) brings the records back.
     */
    public function switched(bool $on): void
    {
        $dns = app(MailDns::class);
        MailDomain::where('status', '!=', MailDomainStatus::Deleting)->where('manage_dns', true)->each(function (MailDomain $domain) use ($dns, $on) {
            $on ? $dns->apply($domain) : $dns->removeWebmail($domain);
        });
        SyncWebmailAddresses::dispatch();
    }

    /**
     * Forget a purged domain's certificate record (its files stay in the
     * agent's store, like any certificate no longer used).
     */
    public function forget(MailDomain $domain): void
    {
        $this->certificate($domain)?->delete();
    }

    /**
     * @return list<array{name: string, certificate: string}>
     */
    private function servedHosts(): array
    {
        return array_values((array) Setting::get(self::SERVED, []));
    }
}
