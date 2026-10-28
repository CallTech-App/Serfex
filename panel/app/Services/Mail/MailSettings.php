<?php

namespace App\Services\Mail;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Support\Hostnames;

/**
 * Server-wide mail settings and the state of the mail server.
 */
class MailSettings
{
    /** Name of the mail server's certificate in the certificate store. */
    public const CERTIFICATE = 'mail';

    /** The webmail applications the server can serve. */
    public const WEBMAIL_APPS = ['roundcube' => 'Roundcube', 'snappymail' => 'SnappyMail'];

    /** Quota choices for new mailboxes, in MB; 0 is no limit. */
    public const QUOTAS = [1024 => '1 GB', 2048 => '2 GB', 5120 => '5 GB', 10240 => '10 GB', 25600 => '25 GB', 0 => 'Sınırsız'];

    /**
     * The mail server's name: SMTP greeting, the address mail clients and
     * webmail use. Null until an administrator sets it.
     */
    public function hostname(): ?string
    {
        return Setting::get('mail.hostname') ?: null;
    }

    /**
     * A name to offer when none is set: the machine's own name when it is a
     * full domain name.
     */
    public function suggestedHostname(): string
    {
        $host = Hostnames::normalize((string) gethostname());

        return Hostnames::isValidDomain($host) ? $host : '';
    }

    public function defaultQuotaMb(): int
    {
        return (int) Setting::get('mail.default_quota_mb', 1024);
    }

    /**
     * Messages a mailbox may send per hour; 0 is no limit.
     */
    public function hourlyLimit(): int
    {
        return (int) Setting::get('mail.hourly_limit', 500);
    }

    public function webmail(): bool
    {
        return (bool) Setting::get('mail.webmail', true);
    }

    /**
     * The webmail application chosen: roundcube (the default) or
     * snappymail. It is kept while webmail is off.
     */
    public function webmailApp(): string
    {
        $app = Setting::get('mail.webmail_app', 'roundcube');

        return array_key_exists($app, self::WEBMAIL_APPS) ? $app : 'roundcube';
    }

    /**
     * The webmail application the mail server serves now, as the last
     * setup left it; null while webmail is off or not set up.
     */
    public function servedWebmailApp(): ?string
    {
        if ($this->webmailUrl() === null) {
            return null;
        }

        return $this->server()['webmail_app'] ?? 'roundcube';
    }

    /**
     * Turn mail on for new sites when the mail server is set up.
     */
    public function autoEnable(): bool
    {
        return (bool) Setting::get('mail.auto', true);
    }

    /**
     * Progress of mail.setup: state installing, ready or failed, with the
     * error, the certificate the server uses and the webmail address.
     *
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return Setting::get('mail.server', []);
    }

    /**
     * True once mail.setup succeeded at least once.
     */
    public function isSetUp(): bool
    {
        return ($this->server()['setup_at'] ?? null) !== null;
    }

    public function webmailUrl(): ?string
    {
        $url = $this->server()['webmail_url'] ?? null;

        return $this->webmail() && $url ? $url : null;
    }

    /**
     * Asks the agent whether it brings a newer SnappyMail than the one
     * served (a Serfex update can); Yazılım Yönetimi then offers it.
     */
    public function checkWebmailUpdate(AgentClient $agent): void
    {
        if ($this->servedWebmailApp() !== 'snappymail' || ($this->server()['state'] ?? null) === 'installing') {
            return;
        }
        $status = $agent->call('mail.status');
        Setting::put('mail.server', [...$this->server(), 'webmail_latest' => $status['webmail_latest'] ?? null]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function saveServer(array $state): void
    {
        Setting::put('mail.server', [...$this->server(), ...$state, 'at' => now()->toIso8601String()]);
    }

    /**
     * @param  array{hostname?: ?string, default_quota_mb?: int, hourly_limit?: int, webmail?: bool, webmail_app?: string, auto?: bool}  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::put('mail.'.$key, $value);
        }
    }

    /**
     * The mail server's certificate record, if one was requested.
     */
    public function certificate(): ?Certificate
    {
        return Certificate::where('kind', 'mail')->first();
    }

    /**
     * The certificate mail.setup should use: the stored one when it covers
     * the current name and has not expired, otherwise "" (self-signed).
     */
    public function certificateName(): string
    {
        $certificate = $this->certificate();
        $hostname = $this->hostname();

        return $certificate !== null && $hostname !== null && $certificate->isInstalled() && ! $certificate->isExpired()
            && in_array($hostname, $certificate->domains, true) ? self::CERTIFICATE : '';
    }

    /**
     * True when the server's name has no usable certificate and none is
     * being requested for it.
     */
    public function needsCertificate(): bool
    {
        $certificate = $this->certificate();
        if ($certificate === null) {
            return true;
        }
        if ($certificate->status === CertificateStatus::Issuing) {
            return false;
        }

        return $certificate->domains !== [$this->hostname()];
    }
}
