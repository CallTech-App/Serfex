<?php

namespace App\Services\Sites;

use App\Enums\MailDomainStatus;
use App\Enums\SiteStatus;
use App\Jobs\DeleteCertificate;
use App\Jobs\RenameSite;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\DnsZone;
use App\Models\FtpAccount;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\SiteRename;
use App\Models\Subdomain;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Dns\Zones;
use App\Services\Files\FtpAccounts;
use App\Services\Laravel\Processes;
use App\Services\Laravel\Redis;
use App\Services\Mail\MailDomains;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use App\Support\Hostnames;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alan adını değiştir: a site moves to another domain and keeps its
 * account, files, databases, settings and history.
 *
 * In order:
 *
 *  1. With the backup option, the site is backed up first (Yedekler, for
 *     Backups::SAFETY_DAYS days); if that fails nothing changes.
 *  2. Its certificate: the current one when it covers the new name;
 *     otherwise a Let's Encrypt certificate is obtained for the new name
 *     before the switch when its DNS points here already, so HTTPS never
 *     stops. When it does not point here yet, the site answers over HTTP
 *     under the new name (the old certificate keeps serving an old name
 *     that stays) and the certificate follows once DNS points here.
 *  3. The agent (site.rename) moves the folder and everything named after
 *     the domain, fits settings files (.env APP_URL, wp-config.php, the
 *     WordPress database) and switches the web server in one reload. A
 *     failure puts everything back; the site keeps its old name.
 *  4. The panel takes the new name: aliases (www.), subdomains under the
 *     old name, the old name as a redirect, an additional domain or gone,
 *     paths in scheduled tasks.
 *  5. Queue workers, the scheduler, Redis, scheduled tasks and SFTP
 *     accounts start again under the new name; the new name gets DNS
 *     records (its own zone, or records in a zone here that holds it);
 *     an unused mail domain moves to the new name, one with mailboxes
 *     stays on the old name.
 *
 * The system account never changes: it is internal, and databases, SSH
 * keys and quotas belong to it. A rename can be undone for
 * SiteRename::UNDO_DAYS days: the site moves back to its old name the
 * same way.
 */
class SiteRenames
{
    public function __construct(
        private readonly AgentClient $agent,
        private readonly SiteDomains $domains,
        private readonly Zones $zones,
        private readonly Certificates $certificates,
        private readonly DnsCheck $dns,
        private readonly Backups $backups,
    ) {}

    /**
     * Why $user may not rename $site now, or null.
     */
    public function refusal(User $user, Site $site): ?string
    {
        if ($user->isCustomer() && ! $user->canSeeSite($site)) {
            return __('Bu site sizin değil.');
        }
        if ($site->status === SiteStatus::Failed) {
            return __('Sitede tamamlanmamış bir işlem var; önce onu yeniden deneyin.');
        }
        if ($site->status !== SiteStatus::Active || $site->provisioned_at === null) {
            return __('Site şu an değişiyor; biraz sonra yeniden deneyin.');
        }
        if ($site->preview()->exists()) {
            return __('Önizleme ortamlarının adı değiştirilemez.');
        }
        if ($site->isSuspended()) {
            return __('Askıdaki sitenin adı değiştirilemez.');
        }
        if ($site->activeLimit() !== null) {
            return __('Siteye geçici kaynak sınırı konmuş; sınır kalkınca yeniden deneyin.');
        }
        $app = $site->laravelApp;
        if ($app !== null && ($app->status->isBusy() || $app->hasRunningDeployment())) {
            return __('Laravel uygulamasında süren bir işlem var; bitince yeniden deneyin.');
        }
        if (SiteRename::where('site_id', $site->id)->whereIn('status', ['queued', 'running'])->exists()) {
            return __('Sitenin adı zaten değiştiriliyor.');
        }

        return null;
    }

    /**
     * Why $site cannot take $name, or null. A name the site itself answers
     * to as an additional domain or a redirected domain may be taken: it
     * becomes the site's name.
     */
    public function nameRefusal(Site $site, string $name, User $user): ?string
    {
        if ($name === $site->domain) {
            return __('Site zaten bu adla açılıyor.');
        }
        if (! Hostnames::isValidDomain($name)) {
            return __('Geçerli bir alan adı girin, örneğin ornek.com.tr.');
        }
        if (in_array($name, $site->aliases ?? [], true) || $this->ownRedirect($site, $name) !== null) {
            return null;
        }
        if (Subdomain::where('site_id', $site->id)->where('name', $name)->exists()) {
            return __(':name bu sitenin alt alan adı; önce Alan adları sayfasından kaldırın.', ['name' => $name]);
        }

        return $this->domains->nameRefusal($site, $name, $user);
    }

    /**
     * Why the old name cannot be kept that way, or null: an additional
     * domain counts against the customer's package.
     */
    public function oldRefusal(Site $site, string $old): ?string
    {
        if ($old === SiteRename::OLD_ALIAS) {
            return $this->domains->limitRefusal($site, 'aliases');
        }

        return null;
    }

    /**
     * What the rename of $site to $name will do, for the dialog.
     *
     * @return array<string, mixed>
     */
    public function plan(Site $site, string $name, bool $www, string $old): array
    {
        $home = rtrim((string) ($site->systemUser->home ?: '/home/'.$site->systemUser->name), '/');
        $own = DnsZone::where('name', $name)->first();
        $parent = $own === null ? $this->zones->parentZone($name) : null;
        $pointing = Cache::remember('serfex.dns-points:'.$name, 300, fn () => $this->dns->check($name));
        $certificate = $site->certificate;
        $mail = MailDomain::withCount('mailboxes', 'forwarders')->where('name', $site->domain)->where('status', '!=', MailDomainStatus::Deleting)->first();
        $app = $site->laravelApp;

        return [
            'from' => $site->domain,
            'to' => $name,
            'folder_from' => '~/web/'.$site->domain,
            'folder_to' => '~/web/'.$name,
            'home' => $home,
            'account' => $site->systemUser->name,
            'dns' => match (true) {
                $own !== null => ['kind' => 'own', 'zone' => $own->name],
                $parent !== null => ['kind' => 'parent', 'zone' => $parent->name, 'label' => substr($name, 0, -strlen('.'.$parent->name))],
                default => ['kind' => 'create', 'zone' => $name],
            },
            'pointing' => $pointing,
            'addresses' => $this->dns->serverAddresses(),
            'certificate' => match (true) {
                $certificate !== null && $certificate->isInstalled() && SiteDomains::covers($certificate->domains ?? [], $name) => 'keep',
                $pointing['ok'] && ($certificate !== null || Setting::get('ssl.auto', true)) => 'issue',
                $certificate !== null || Setting::get('ssl.auto', true) => 'later',
                default => 'none',
            },
            'mail' => $mail === null ? null : ['mailboxes' => $mail->mailboxes_count, 'forwarders' => $mail->forwarders_count, 'moves' => $mail->mailboxes_count === 0 && $mail->forwarders_count === 0],
            'laravel' => $app !== null,
            'workers' => $app === null ? 0 : $app->workers()->count() + $app->processes()->where('enabled', true)->count() + ($app->scheduler ? 1 : 0),
            'redis' => $app?->redis_port !== null,
            'wordpress' => $site->wordpress !== null,
            'cron' => CronJob::where('site_id', $site->id)->count(),
            'ftp' => FtpAccount::where('site_id', $site->id)->count(),
            'subdomains' => $site->subdomains()->where('name', 'like', '%.'.$site->domain)->pluck('name')->map(fn ($n) => substr($n, 0, -strlen($site->domain)).$name)->all(),
            'www' => $www && ! str_starts_with($name, 'www.'),
            'old' => $old,
            'had_www' => $site->wwwPair() !== null,
        ];
    }

    /**
     * Whether www.<name> should be offered checked: when the site has a
     * www. name now and the new name is not a subdomain hosted here.
     */
    public function suggestWww(Site $site, string $name): bool
    {
        return $site->wwwPair() !== null && ! str_starts_with($name, 'www.')
            && DnsZone::where('name', $name)->doesntExist() && $this->zones->parentZone($name) === null;
    }

    /**
     * Queues the rename.
     */
    public function start(Site $site, string $name, bool $www, string $old, bool $backup, ?User $user, ?SiteRename $undoOf = null): SiteRename
    {
        $rename = DB::transaction(function () use ($site, $name, $www, $old, $backup, $user, $undoOf) {
            $site->update(['status' => SiteStatus::Renaming, 'last_error' => null]);

            return SiteRename::create([
                'site_id' => $site->id,
                'from_domain' => $site->domain,
                'to_domain' => $name,
                'options' => ['www' => $www && ! str_starts_with($name, 'www.'), 'old' => $old, 'backup' => $backup],
                'run' => 'rename-'.Str::lower(Str::random(12)),
                'status' => 'queued',
                'undo_of_id' => $undoOf?->id,
                'user_id' => $user?->id,
            ]);
        });
        RenameSite::dispatch($rename, $backup);

        return $rename;
    }

    /**
     * The latest rename of the site, if any.
     */
    public function latest(Site $site): ?SiteRename
    {
        return SiteRename::where('site_id', $site->id)->latest('id')->first();
    }

    /**
     * Why $rename cannot be undone now, or null.
     */
    public function undoRefusal(SiteRename $rename, User $user): ?string
    {
        $site = $rename->site;
        if ($site === null || $rename->undoUntil() === null || $rename->undoUntil()->isPast()) {
            return __('Bu değişiklik artık geri alınamaz.');
        }
        if ($this->latest($site)?->id !== $rename->id || $site->domain !== $rename->to_domain) {
            return __('Site o zamandan beri yeniden değişti.');
        }
        if ($why = $this->refusal($user, $site)) {
            return $why;
        }
        $redirect = $this->ownRedirect($site, $rename->from_domain);
        if (! in_array($rename->from_domain, $site->aliases ?? [], true) && $redirect === null && ($taken = $this->domains->taken($rename->from_domain))) {
            return __('Eski ad artık başka bir yerde kullanılıyor: :taken', ['taken' => $taken]);
        }

        return null;
    }

    /**
     * Moves the site back to its old name, the way it was renamed.
     */
    public function undo(SiteRename $rename, User $user): SiteRename|string
    {
        if ($why = $this->undoRefusal($rename, $user)) {
            return $why;
        }
        $before = $rename->before ?? [];

        return $this->start($rename->site, $rename->from_domain, (bool) ($before['had_www'] ?? false), SiteRename::OLD_NONE, false, $user, $rename);
    }

    /**
     * Runs a queued rename (RenameSite calls it).
     */
    public function run(SiteRename $rename): void
    {
        $rename->update(['status' => 'running', 'started_at' => now()]);
        $site = $rename->site()->with('systemUser.customer', 'certificate', 'subdomains', 'redirects', 'wordpress', 'laravelApp')->first();
        if ($site === null || $site->domain !== $rename->from_domain) {
            $this->finish($rename, $site, __('Site artık yok ya da adı değişti.'));

            return;
        }
        try {
            $this->runFor($rename, $site);
        } catch (Throwable $e) {
            report($e);
            $this->finish($rename, $site->fresh(), $e instanceof AgentException ? $e->forPeople() : $e->getMessage());
        }
    }

    private function runFor(SiteRename $rename, Site $site): void
    {
        $from = $site->domain;
        $to = $rename->to_domain;
        $options = $rename->options;
        $old = $options['old'] ?? SiteRename::OLD_REDIRECT;
        $user = $rename->user ?? User::where('is_admin', true)->orderBy('id')->first();
        $notes = [];

        if ($options['backup'] ?? false) {
            $backup = $this->backups->takeSafety($site, $rename->user);
            $rename->update(['backup_id' => $backup->id]);
            if ($backup->status !== 'succeeded') {
                $error = $backup->error ?: $backup->items()->where('status', 'failed')->value('error') ?: __('Yedek tamamlanamadı.');
                $this->backups->purge($backup);
                $rename->update(['backup_id' => null]);
                $this->finish($rename, $site, __('Sitenin yedeği alınamadı, hiçbir şey değişmedi: :error', ['error' => $error]));

                return;
            }
            $site->refresh()->load('systemUser.customer', 'certificate', 'subdomains', 'redirects', 'wordpress', 'laravelApp');
        }
        if ($user !== null && ($why = $this->nameRefusal($site, $to, $user))) {
            $this->finish($rename, $site, $why);

            return;
        }

        // The site under its new name, in memory until the server has it.
        $hadWww = $site->wwwPair();
        $before = [
            'domain' => $from,
            'aliases' => $site->aliases ?? [],
            'preferred_host' => $site->preferred_host,
            'certificate_id' => $site->certificate_id,
            'had_www' => $hadWww !== null,
        ];
        $aliases = array_values(array_filter($site->aliases ?? [], fn ($a) => $a !== $to && $a !== $hadWww && $a !== 'www.'.$to));
        $wwwTaker = ($options['www'] ?? false) ? $this->domains->siteFor('www.'.$to) : null;
        if ($wwwTaker !== null && $wwwTaker->id !== $site->id) {
            $notes[] = ['code' => 'www_taken', 'subject' => (string) $this->domains->taken('www.'.$to)];
        } elseif ($options['www'] ?? false) {
            $aliases[] = 'www.'.$to;
        }
        if ($old === SiteRename::OLD_ALIAS) {
            $aliases = [...$aliases, $from, ...($hadWww !== null ? [$hadWww] : [])];
        }
        $aliases = array_values(array_unique($aliases));
        $subdomains = $site->subdomains->map(function (Subdomain $s) use ($from, $to) {
            if (str_ends_with($s->name, '.'.$from)) {
                $s->name = substr($s->name, 0, -strlen($from)).$to;
            }

            return $s;
        });
        $dropRedirect = $this->ownRedirect($site, $to);
        $redirects = $site->redirects->reject(fn (SiteRedirect $r) => $r->id === $dropRedirect?->id)->values();
        $newRedirect = null;
        if ($old === SiteRename::OLD_REDIRECT) {
            $newRedirect = new SiteRedirect([
                'site_id' => $site->id,
                'kind' => SiteRedirect::DOMAIN,
                'source' => $from,
                'with_www' => $hadWww !== null && $hadWww === 'www.'.$from,
                'target' => 'https://'.$to,
                'code' => 301,
                'keep_path' => true,
            ]);
            $redirects->push($newRedirect);
        }
        $site->domain = $to;
        $site->aliases = $aliases;
        $site->setRelation('subdomains', $subdomains);
        $site->setRelation('redirects', $redirects);

        // A certificate for the new name, before anything switches.
        [$certificate, $how] = $this->certificateFor($site, $from, $old, $notes);
        $site->certificate_id = $certificate?->id;
        $site->setRelation('certificate', $certificate);
        if ($newRedirect !== null && ($certificate === null || ! SiteDomains::covers($certificate->domains ?? [], $site->primaryHost()))) {
            // Without HTTPS for the new name yet, the redirect goes to
            // http; the site moves visitors on to https once it can.
            $newRedirect->target = 'http://'.$site->primaryHost();
        } elseif ($newRedirect !== null) {
            $newRedirect->target = 'https://'.$site->primaryHost();
        }

        try {
            $result = (array) $this->agent->call('site.rename', [
                'run' => $rename->run,
                'from' => $from,
                'site' => $site->agentSpec(),
                'hosts' => $this->hostPairs($from, $hadWww, $to, $options['www'] ?? false),
                'wordpress' => (string) ($site->wordpress?->path ?? ''),
            ], config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            // Nothing changed on the server: what was stopped starts again
            // under the old name, and a certificate made for the new name
            // goes.
            $fresh = Site::with('systemUser.customer', 'laravelApp')->find($site->id);
            $this->restart($fresh, $notes);
            if ($how === 'issued' && $certificate !== null) {
                DeleteCertificate::dispatch($certificate);
            }
            $rename->update(['notes' => $notes]);
            $this->finish($rename, $fresh, $e->forPeople());

            return;
        }

        // The panel takes the new name.
        DB::transaction(function () use ($site, $from, $to, $result, $subdomains, $dropRedirect, $newRedirect) {
            $site->fill([
                'status' => SiteStatus::Active,
                'last_error' => null,
                'document_root' => $result['document_root'] ?? $site->document_root,
            ])->save();
            foreach ($subdomains as $sub) {
                if ($sub->isDirty('name')) {
                    $sub->save();
                }
            }
            $dropRedirect?->delete();
            $newRedirect?->save();
            // Paths to the old folder in scheduled tasks (they work through
            // the link left there too).
            foreach (CronJob::withTrashed()->where('site_id', $site->id)->get() as $task) {
                $command = preg_replace('#(/web/)'.preg_quote($from, '#').'(?![A-Za-z0-9.-])#', '${1}'.$to, $task->command);
                if ($command !== $task->command) {
                    $task->update(['command' => $command]);
                }
            }
        });
        $site = Site::with('systemUser.customer', 'certificate', 'certificates', 'subdomains', 'redirects', 'laravelApp')->find($site->id);
        foreach ((array) ($result['notes'] ?? []) as $note) {
            $notes[] = ['code' => (string) ($note['code'] ?? ''), 'subject' => (string) ($note['subject'] ?? '')];
        }

        // What runs under the name starts again; DNS, mail, certificate.
        $this->restart($site, $notes);
        $this->dnsFor($site, $from, $old, $subdomains->pluck('name')->all(), $before['aliases'], $notes, create: $rename->undo_of_id === null);
        $this->mailFor($site, $from, $notes);
        if ($site->copy_of_id !== null) {
            // A staging copy's WordPress keeps its e-mail and WP-Cron to
            // itself under the new name too.
            try {
                app(SiteCopies::class)->applyStaging($site);
            } catch (AgentException) {
                $notes[] = ['code' => 'staging_failed', 'subject' => $to];
            }
        }
        if ($before['certificate_id'] !== null && $before['certificate_id'] !== $site->certificate_id) {
            // The certificate of the old name is kept for a while, in case
            // the rename is undone soon.
            $replaced = Certificate::find($before['certificate_id']);
            if ($replaced !== null) {
                DeleteCertificate::dispatch($replaced)->delay(now()->addSeconds(Certificates::UNDO_SECONDS));
            }
        }
        if ($how !== 'kept' && $how !== 'issued' && ($before['certificate_id'] !== null || Setting::get('ssl.auto', true))) {
            // The new name gets its certificate once its DNS points here
            // (serfex:ssl-renew tries every hour).
            $this->certificates->letsEncrypt($site, auto: true, separate: $site->certificate !== null);
            $notes[] = ['code' => 'certificate_later', 'subject' => $to];
        }

        $rename->update(['before' => $before, 'notes' => $notes]);
        $this->finish($rename, $site, null);
    }

    /**
     * The certificate the site serves under its new name, and how it was
     * found: kept (the current one covers it), reused (another of the
     * site's), issued (obtained now), kept_old (the new name does not
     * point here yet; the current one keeps serving the old name that
     * stays) or none.
     *
     * @param  list<array{code: string, subject: string}>  $notes
     * @return array{0: Certificate|null, 1: string}
     */
    private function certificateFor(Site $site, string $from, string $old, array &$notes): array
    {
        $to = $site->domain;
        $current = $site->certificate;
        $usable = fn (?Certificate $c) => $c !== null && $c->isInstalled() && ! $c->isExpired();
        if ($usable($current) && SiteDomains::covers($current->domains ?? [], $to)) {
            return [$current, 'kept'];
        }
        $other = $site->certificates()->get()->first(fn (Certificate $c) => $c->id !== $current?->id && $usable($c) && SiteDomains::covers($c->domains ?? [], $to));
        if ($other !== null) {
            return [$other, 'reused'];
        }
        $wanted = $current !== null || Setting::get('ssl.auto', true);
        $pointing = array_values(array_filter($site->hostnames(), fn ($n) => $this->dns->check($n)['ok']));
        if ($wanted && in_array($to, $pointing, true)) {
            try {
                $issued = $this->certificates->issueNow($site, $to, $pointing);
                $notes[] = ['code' => 'certificate_issued', 'subject' => implode(', ', $pointing)];

                return [$issued, 'issued'];
            } catch (AgentException $e) {
                $notes[] = ['code' => 'certificate_failed', 'subject' => Certificates::failure($e)[0]];
            }
        }
        if ($usable($current) && $old !== SiteRename::OLD_NONE && SiteDomains::covers($current->domains ?? [], $from)) {
            return [$current, 'kept_old'];
        }

        return [null, 'none'];
    }

    /**
     * Old host names and their new ones, www. first, for settings files
     * and WordPress.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function hostPairs(string $from, ?string $hadWww, string $to, bool $www): array
    {
        $pairs = [];
        if ($hadWww !== null) {
            $pairs[] = [$hadWww, $www ? (str_starts_with($hadWww, 'www.') ? 'www.'.$to : $to) : $to];
        }
        $pairs[] = [$from, $to];
        usort($pairs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

        return $pairs;
    }

    /**
     * Starts queue workers, the scheduler, Redis, scheduled tasks and SFTP
     * accounts under the site's name. What fails is noted; the site itself
     * works.
     *
     * @param  list<array{code: string, subject: string}>  $notes
     */
    private function restart(?Site $site, array &$notes): void
    {
        if ($site === null) {
            return;
        }
        $app = $site->laravelApp;
        if ($app !== null) {
            $app->setRelation('site', $site);
            if ($app->redis_port !== null) {
                try {
                    app(Redis::class)->connect($app);
                } catch (AgentException $e) {
                    $notes[] = ['code' => 'redis_failed', 'subject' => $e->forPeople()];
                }
            }
            try {
                app(Processes::class)->apply($app->load('workers', 'processes'));
                $app->update(['processes_state' => 'ok', 'processes_error' => null]);
            } catch (AgentException $e) {
                $app->update(['processes_state' => 'failed', 'processes_error' => $e->forPeople()]);
                $notes[] = ['code' => 'workers_failed', 'subject' => $e->forPeople()];
            }
        }
        if (CronJob::withTrashed()->where('site_id', $site->id)->exists()) {
            try {
                app(CronJobs::class)->apply($site);
            } catch (AgentException $e) {
                $notes[] = ['code' => 'cron_failed', 'subject' => $e->forPeople()];
            }
        }
        foreach (FtpAccount::withTrashed()->where('site_id', $site->id)->with('site', 'systemUser.customer')->get() as $account) {
            try {
                app(FtpAccounts::class)->apply($account);
            } catch (AgentException $e) {
                $notes[] = ['code' => 'ftp_failed', 'subject' => $account->name.': '.$e->forPeople()];
            }
        }
    }

    /**
     * DNS of the new name: its own zone or records in the zone here that
     * holds it, like a new site's; the www. name and moved subdomains too.
     * When the old name goes, the records Serfex wrote for it in a parent
     * zone go; a zone of its own stays (it may hold mail).
     *
     * @param  list<string>  $subdomains
     * @param  list<string>  $oldAliases
     * @param  list<array{code: string, subject: string}>  $notes
     */
    private function dnsFor(Site $site, string $from, string $old, array $subdomains, array $oldAliases, array &$notes, bool $create = true): void
    {
        $zone = DnsZone::where('name', $site->domain)->first();
        $parent = $zone === null ? $this->zones->parentZone($site->domain) : null;
        $created = $zone === null && $parent === null;
        // An undo does not make a zone the old name never had here.
        if ($create || ! $created) {
            $this->zones->forSite($site);
        }
        foreach ($subdomains as $name) {
            if (str_ends_with($name, '.'.$site->domain)) {
                $this->zones->addHost($name, $site);
            }
        }
        if ($create || ! $created) {
            $notes[] = ['code' => $created ? 'dns_zone_created' : 'dns_records', 'subject' => $created ? $site->domain : ($zone ?? $parent)->name];
        }
        if ($old === SiteRename::OLD_NONE) {
            $gone = [$from, ...array_filter($oldAliases, fn ($a) => $a === 'www.'.$from)];
            foreach ($gone as $name) {
                if (! in_array($name, $site->hostnames(), true)) {
                    $this->zones->removeHost($name);
                }
            }
        }
    }

    /**
     * Mail: an unused mail domain (no mailboxes, no forwarders) moves to
     * the new name; one in use stays on the old name, so addresses keep
     * receiving mail.
     *
     * @param  list<array{code: string, subject: string}>  $notes
     */
    private function mailFor(Site $site, string $from, array &$notes): void
    {
        $mail = MailDomain::withCount('mailboxes', 'forwarders')->where('name', $from)->where('status', '!=', MailDomainStatus::Deleting)->first();
        if ($mail === null) {
            return;
        }
        if ($mail->mailboxes_count > 0 || $mail->forwarders_count > 0) {
            $notes[] = ['code' => 'mail_stays', 'subject' => $from];

            return;
        }
        $service = app(MailDomains::class);
        try {
            $service->disable($mail, purgeNow: true);
            $service->forSite($site);
            $notes[] = ['code' => 'mail_moved', 'subject' => $site->domain];
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A note of a rename, in words, and whether it needs a look.
     *
     * @param  array{code: string, subject: string}  $note
     * @return array{0: string, 1: bool}
     */
    public static function noteText(array $note): array
    {
        $s = ['subject' => $note['subject'] ?? ''];

        return match ($note['code'] ?? '') {
            'configs' => [__('Ayar dosyalarındaki adres ve klasör yolu güncellendi: :subject', $s), false],
            'config_cache' => [__('Laravel yapılandırma önbelleği yeni adresle yeniden oluşturuldu (:subject).', $s), false],
            'config_cache_removed' => [__('Laravel yapılandırma önbelleği oluşturulamadı, kaldırıldı; uygulama .env dosyasını doğrudan okuyor (:subject).', $s), true],
            'links' => [__(':subject bağlantı yeni klasöre yönlendirildi (ör. storage bağlantısı).', $s), false],
            'wordpress' => [__('WordPress veritabanındaki adresler yeni ada çevrildi.'), false],
            'certificate_issued' => [__('Yeni ad için Let\'s Encrypt sertifikası değişiklikten önce alındı: :subject', $s), false],
            'certificate_failed' => [__('Yeni ad için sertifika alınamadı: :subject', $s), true],
            'certificate_later' => [__(':subject bu sunucuya işaret edince sertifika kendiliğinden alınır; o zamana kadar yeni ad http ile açılır.', $s), true],
            'redis_failed' => [__('Redis yeniden başlatılamadı: :subject', $s), true],
            'workers_failed' => [__('Kuyruk işçileri yeniden başlatılamadı: :subject', $s), true],
            'cron_failed' => [__('Zamanlanmış görevler yazılamadı: :subject', $s), true],
            'ftp_failed' => [__('SFTP hesabı güncellenemedi: :subject', $s), true],
            'dns_zone_created' => [__(':subject için bu sunucuda DNS bölgesi oluşturuldu. Alan adının DNS\'i başka yerdeyse A kaydını bu sunucuya yönlendirin.', $s), false],
            'dns_records' => [__('Adres kayıtları :subject bölgesine yazıldı.', $s), false],
            'mail_stays' => [__('Posta kutuları :subject adında kaldı; o adresler posta almaya devam ediyor.', $s), false],
            'mail_moved' => [__('E-posta (kutusu yoktu) yeni ada taşındı: :subject', $s), false],
            'www_taken' => [__('www. adı eklenmedi: :subject', $s), true],
            'staging_failed' => [__('Hazırlık kopyasının e-posta ve WP-Cron kısıtı yeni ada göre yazılamadı; Siteyi kopyala sayfasından yeniden kaydedin.'), true],
            default => [(string) ($note['subject'] ?? ''), false],
        };
    }

    /**
     * The site's own domain redirect whose source is $name, if any.
     */
    private function ownRedirect(Site $site, string $name): ?SiteRedirect
    {
        return SiteRedirect::where('site_id', $site->id)->where('kind', SiteRedirect::DOMAIN)->where('source', $name)->first();
    }

    /**
     * Records the outcome; a rename that did not happen leaves the site
     * as it was, under its old name.
     */
    private function finish(SiteRename $rename, ?Site $site, ?string $error): void
    {
        if ($error !== null && $site !== null && $site->status === SiteStatus::Renaming) {
            $site->update(['status' => SiteStatus::Active]);
        }
        $rename->update([
            'status' => $error === null ? 'succeeded' : 'failed',
            'error' => $error,
            'finished_at' => now(),
        ]);
        if ($error === null && $rename->undo_of_id !== null) {
            SiteRename::whereKey($rename->undo_of_id)->update(['status' => 'undone']);
        }
    }
}
