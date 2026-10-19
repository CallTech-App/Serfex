<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\DnsZone;
use App\Models\LaravelPreview;
use App\Models\MailDomain;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\Databases;
use App\Services\Dns\Zones;
use App\Services\Mail\MailDomains;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Removes a site from the server (configuration and files) and then from
 * the panel. The system account goes too when asked and no site uses it;
 * the site's DNS zone goes when asked, otherwise it stays as a DNS-only
 * domain. Its mail (mailboxes and stored mail) goes when asked, otherwise
 * the domain keeps receiving mail without the site. Its databases go when
 * asked (each after a backup), otherwise they stay with the account.
 */
class DeleteSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Site $site, public bool $removeUser = true, public bool $removeZone = false, public bool $removeMail = false, public bool $removeDatabases = false) {}

    public function handle(AgentClient $agent, Zones $zones): void
    {
        $site = $this->site->fresh(['systemUser']);
        if ($site === null) {
            return;
        }
        // Preview environments of the site's application are sites of
        // their own; they go first.
        $app = $site->laravelApp;
        if ($app !== null) {
            foreach ($app->previews()->with('site', 'app.site')->get() as $preview) {
                $preview->update(['status' => 'deleting']);
                DeleteLaravelPreview::dispatchSync($preview);
            }
        }
        $user = $site->systemUser;
        $lastSite = $user->sites()->whereKeyNot($site->id)->doesntExist();

        try {
            // Queue workers and the scheduler run as the site's account
            // from its directory; they stop before it goes.
            if ($site->laravelApp()->exists()) {
                $agent->call('laravel.remove', [
                    'domain' => $site->domain,
                    'user' => $user->name,
                    'php_version' => $site->php_version,
                    'remove_files' => false,
                ], config('servik.agent.job_timeout'));
            }
            $result = $agent->call('site.delete', [
                'domain' => $site->domain,
                'user' => $user->name,
                'remove_files' => true,
                'remove_user' => $this->removeUser && $lastSite,
            ], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);

            return;
        }

        if ($this->removeDatabases) {
            try {
                app(Databases::class)->deleteForSite($site);
            } catch (AgentException $e) {
                // The site is gone already; what is left shows in the list.
                report($e);
            }
        }

        // The vhost is gone, so the site's certificates can go too. One the
        // agent refuses to delete stays on disk, readable only by root.
        foreach ($site->certificates()->pluck('name') as $name) {
            try {
                $agent->call('ssl.delete', ['name' => $name], config('servik.agent.job_timeout'));
            } catch (AgentException) {
            }
        }

        $zone = DnsZone::where('site_id', $site->id)->first();
        $mailDomain = MailDomain::where('site_id', $site->id)->orWhere('name', $site->domain)->first();
        DB::transaction(function () use ($site, $user, $result) {
            // A preview environment's site deleted from the site list.
            LaravelPreview::where('site_id', $site->id)->where('status', '!=', 'deleting')->delete();
            $site->delete();
            if ($result['user_removed'] ?? false) {
                $user->delete();
            }
        });
        if ($mailDomain !== null && $this->removeMail) {
            app(MailDomains::class)->disable($mailDomain->fresh(), purgeNow: true);
        }
        if ($zone !== null && $this->removeZone) {
            $zones->delete($zone->fresh());
        }
    }
}
