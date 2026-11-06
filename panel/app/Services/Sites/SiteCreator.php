<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Jobs\ProvisionSite;
use App\Models\CloudflareAccount;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareZones;
use App\Services\Components\Components;
use App\Services\Customers\Customers;
use App\Services\Dns\Zones;
use App\Services\Mail\MailDomains;
use Illuminate\Support\Facades\DB;

/**
 * Creates a site once its form (or API request) is valid: the record,
 * the provisioning job, its DNS zone and, when the package has mail, its
 * mail domain. "Site ekle" and the panel API both go through here.
 */
class SiteCreator
{
    public function __construct(
        private readonly Customers $customers,
        private readonly Components $components,
        private readonly Zones $zones,
        private readonly MailDomains $mail,
        private readonly CloudflareZones $cloudflare,
    ) {}

    /**
     * Set when the site's DNS could not go to Cloudflare as asked; the
     * site then has its zone on this server.
     */
    public ?string $dnsError = null;

    /**
     * @param  Customer|null  $customer  the customer the site is for; their account is used
     * @param  Customer|null  $owner  who the site ends up with (the customer, or the account's)
     * @param  string  $preferred  Site::PREFER_*: which of the domain and its www. name visitors end up on
     * @param  array{account: CloudflareAccount, zone: ?array<string, mixed>, proxied: bool}|null  $cloudflare  put the site's DNS at Cloudflare: in the zone found there, or (zone null) a zone added to the account
     */
    public function create(string $domain, string $account, string $php, bool $www, ?Customer $customer, ?Customer $owner, string $preferred = Site::PREFER_BARE, ?array $cloudflare = null): Site
    {
        $this->dnsError = null;
        $site = DB::transaction(function () use ($domain, $account, $php, $www, $customer, $preferred) {
            $user = $customer !== null ? $this->customers->account($customer) : SystemUser::firstOrCreate(['name' => $account]);

            return $user->sites()->create([
                'domain' => $domain,
                'aliases' => $www && ! str_starts_with($domain, 'www.') ? ['www.'.$domain] : [],
                'php_version' => $php,
                'preferred_host' => in_array($preferred, Site::PREFERRED, true) ? $preferred : Site::PREFER_BARE,
                'web_server' => $this->components->defaultStack(),
                'status' => SiteStatus::Provisioning,
            ]);
        });

        ProvisionSite::dispatch($site);
        // DNS does not wait for the web server: the zone is published even
        // if provisioning fails and is retried.
        if ($cloudflare !== null) {
            try {
                $cfZone = $cloudflare['zone'] ?? $this->cloudflare->create($cloudflare['account'], $domain);
                $this->cloudflare->attachSite($site, $cloudflare['account'], $cfZone, $cloudflare['proxied']);
            } catch (CloudflareException $e) {
                report($e);
                $this->dnsError = $e->getMessage();
                $this->zones->forSite($site);
            }
        } else {
            $this->zones->forSite($site);
        }
        if ($owner === null || $owner->package->allows('mail')) {
            $this->mail->forSite($site);
        }

        return $site;
    }
}
