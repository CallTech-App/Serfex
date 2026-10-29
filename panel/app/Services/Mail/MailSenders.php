<?php

namespace App\Services\Mail;

use App\Jobs\SyncMailDomain;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * Which domains each site's system user may send mail as: its sites'
 * names, aliases, subdomains and redirected domains, and their mail
 * domains. The agent limits sendmail (PHP's mail()) to them, so one
 * customer cannot send as another customer's domain with this server's
 * SPF and DKIM.
 */
class MailSenders
{
    public function __construct(private AgentClient $agent, private MailSettings $settings) {}

    /**
     * Every system user with its domains; one without sites may send only
     * as itself.
     *
     * @return array<string, list<string>>
     */
    public function logins(): array
    {
        $logins = [];
        SystemUser::with('sites.subdomains', 'sites.redirects', 'sites.mailDomain')->orderBy('name')->each(function (SystemUser $user) use (&$logins) {
            if ($user->name === 'root') {
                return;
            }
            $logins[$user->name] = self::domainsOf($user);
        });

        return $logins;
    }

    /**
     * The domains a system user may send as, lower-case and sorted.
     *
     * @return list<string>
     */
    public static function domainsOf(SystemUser $user): array
    {
        $user->loadMissing('sites.subdomains', 'sites.redirects', 'sites.mailDomain');
        $domains = [];
        foreach ($user->sites as $site) {
            $domains = [...$domains, ...$site->hostnames()];
            if ($site->mailDomain !== null) {
                $domains[] = $site->mailDomain->name;
            }
        }
        $domains = array_values(array_unique(array_map('strtolower', $domains)));
        sort($domains);

        return $domains;
    }

    /**
     * Sends the limits to the agent. False when the mail server is not
     * set up yet: setting it up sends them.
     *
     * @throws AgentException
     */
    public function sync(): bool
    {
        if (! $this->settings->isSetUp()) {
            return false;
        }
        try {
            $this->agent->call('mail.senders.apply', ['logins' => (object) $this->logins()], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() === SyncMailDomain::NOT_SET_UP) {
                return false;
            }
            throw $e;
        }

        return true;
    }
}
