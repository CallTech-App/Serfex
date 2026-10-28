<?php

namespace App\Services\Sites;

use App\Enums\SiteStatus;
use App\Jobs\CopySite;
use App\Jobs\DeleteSite;
use App\Jobs\ProvisionSite;
use App\Models\BackupRestore;
use App\Models\Database;
use App\Models\Site;
use App\Models\SiteCopy;
use App\Models\User;
use App\Models\WordPressInstall;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Backups\Restores;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Dns\Zones;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Siteyi kopyala: a staging copy of a site on a name of its own
 * (staging.<domain>), under the same account, PHP version and web server,
 * with copies of its databases (<name>_copy, the same database users
 * having the same access). The copy can be brought up to date from the
 * live site, and published back onto it: the live site is backed up first
 * and the publish can be undone from that backup for Backups::SAFETY_DAYS
 * days; a publish that fails is undone at once.
 *
 * Mail, scheduled tasks, FTP accounts and Laravel's queue workers are not
 * copied. A copy asks search engines to stay away until told otherwise.
 */
class SiteCopies
{
    /** The first label of a suggested name: staging.example.com. */
    public const LABEL = 'staging';

    /** Appended to a copied database's name. */
    public const DATABASE_SUFFIX = '_copy';

    public function __construct(
        private readonly AgentClient $agent,
        private readonly SiteDomains $domains,
        private readonly Zones $zones,
        private readonly Quotas $quotas,
        private readonly Databases $databases,
        private readonly Backups $backups,
        private readonly Restores $restores,
        private readonly SiteCache $cache,
        private readonly WordPressSites $wordpress,
    ) {}

    /**
     * A free name for a copy of $site: staging.example.com, then
     * staging2.example.com…
     */
    public function suggestedDomain(Site $site): string
    {
        $base = str_starts_with($site->domain, 'www.') ? substr($site->domain, 4) : $site->domain;
        for ($i = 1; ; $i++) {
            $name = self::LABEL.($i > 1 ? $i : '').'.'.$base;
            if ($this->domains->taken($name) === null || $i >= 50) {
                return $name;
            }
        }
    }

    /**
     * Why $user may not make a copy of $site, or null.
     */
    public function createRefusal(User $user, Site $site): ?string
    {
        if ($site->copy_of_id !== null) {
            return __('Bu site zaten bir kopya; kopyası yerine kaynağının yeni bir kopyasını oluşturun.');
        }
        if ($site->preview !== null) {
            return __('Önizleme ortamları kopyalanamaz.');
        }
        if ($refusal = $this->siteRefusal($site)) {
            return $refusal;
        }
        if ($user->isCustomer() && ! $user->canSeeSite($site)) {
            return __('Bu site sizin değil.');
        }

        return $this->quotaRefusal($site, $this->sourceDatabases($site)->count());
    }

    /**
     * Why the copy cannot be refreshed from its live site or published to
     * it now, or null.
     */
    public function runRefusal(Site $copy, string $kind): ?string
    {
        $source = $copy->copyOf;
        if ($source === null) {
            return __('Kaynak site artık yok.');
        }
        foreach ([$copy, $source] as $site) {
            if ($refusal = $this->siteRefusal($site)) {
                return $refusal;
            }
        }
        if ($kind === SiteCopy::PUBLISH && $source->laravelApp !== null) {
            return __('Laravel uygulamaları canlıya Git dağıtımıyla ya da önizleme ortamlarıyla çıkar; kopya üzerine aktarılmaz.');
        }
        if ($kind === SiteCopy::REFRESH) {
            $missing = $this->sourceDatabases($source)->filter(fn (Database $d) => $this->copyOf($copy, $d) === null)->count();

            return $this->quotaRefusal($copy, $missing, sites: false);
        }

        return null;
    }

    /**
     * Why the name may not be used for a copy of $site, or null.
     */
    public function domainRefusal(Site $site, string $name, User $user): ?string
    {
        return $this->domains->nameRefusal($site, $name, $user);
    }

    /**
     * Creates the copy site and queues the copy into it.
     */
    public function create(Site $source, string $domain, bool $databases, bool $noindex, ?User $user): SiteCopy
    {
        $copy = DB::transaction(function () use ($source, $domain, $noindex) {
            return $source->systemUser->sites()->create([
                'domain' => $domain,
                'aliases' => [],
                'copy_of_id' => $source->id,
                'php_version' => $source->php_version,
                'web_server' => $source->web_server,
                'web_root' => $source->web_root,
                'php_settings' => $source->php_settings,
                'denied_paths' => $source->denied_paths,
                'error_pages' => $source->error_pages,
                'nginx_directives' => $source->nginx_directives,
                'apache_directives' => $source->apache_directives,
                'waf_mode' => $source->waf_mode,
                'node_version' => $source->node_version,
                'preferred_host' => Site::PREFER_NONE,
                'noindex' => $noindex,
                'status' => SiteStatus::Provisioning,
            ]);
        });
        // Only a label in a zone hosted here; a domain whose DNS is
        // elsewhere needs an A record there.
        if ($this->zones->parentZone($domain) !== null) {
            $this->zones->forSite($copy);
        }

        return $this->queue($source, $copy, SiteCopy::CREATE, $databases ? SiteCopy::PARTS : ['files'], $user);
    }

    /**
     * Queues bringing the copy up to date from its live site, or putting
     * it onto the live site.
     *
     * @param  list<string>  $parts
     */
    public function start(Site $copy, string $kind, array $parts, ?User $user): SiteCopy
    {
        return $this->queue($copy->copyOf, $copy, $kind, $parts, $user);
    }

    /**
     * @param  list<string>  $parts
     */
    private function queue(Site $source, Site $copy, string $kind, array $parts, ?User $user): SiteCopy
    {
        $run = SiteCopy::create([
            'source_id' => $source->id,
            'site_id' => $copy->id,
            'domain' => $copy->domain,
            'kind' => $kind,
            'parts' => array_values(array_intersect(SiteCopy::PARTS, $parts)),
            'run' => 'copy-'.Str::lower(Str::random(12)),
            'status' => 'queued',
            'user_id' => $user?->id,
        ]);
        CopySite::dispatch($run);

        return $run;
    }

    /**
     * Undoes a publish: the live site's files and databases come back from
     * the backup taken before it. Returns why not, as text.
     */
    public function undo(SiteCopy $run, ?User $user): SiteCopy|string
    {
        if ($run->kind !== SiteCopy::PUBLISH || $run->status !== 'succeeded' || ! $this->undoable($run)) {
            return __('Bu aktarma artık geri alınamaz.');
        }
        $item = $run->backup->items()->where('site_id', $run->source_id)->where('status', 'succeeded')->first();
        if ($item === null) {
            return __('Aktarmadan önceki yedek bulunamadı.');
        }
        $restore = $this->restores->start($item, $this->restoreParts($run->parts), false, $user);
        if (is_string($restore)) {
            return $restore;
        }
        $run->update(['status' => 'undone', 'restore_id' => $restore->id]);

        return $run;
    }

    /**
     * Whether the backup taken before a publish is still there.
     */
    public function undoable(SiteCopy $run): bool
    {
        $backup = $run->backup;

        return $backup !== null && $backup->isUsable()
            && ($this->backups->expiresAt($backup) === null || $this->backups->expiresAt($backup)->isFuture());
    }

    /**
     * Runs a queued copy (CopySite calls it).
     */
    public function run(SiteCopy $run): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);
        $copy = $run->site()->with('systemUser')->first();
        $source = $run->source()->with('systemUser')->first();
        if ($copy === null || $source === null) {
            $this->finish($run, __('Site artık yok.'));

            return;
        }
        try {
            match ($run->kind) {
                SiteCopy::CREATE => $this->runCreate($run, $source, $copy),
                SiteCopy::REFRESH => $this->runRefresh($run, $source, $copy),
                default => $this->runPublish($run, $source, $copy),
            };
        } catch (AgentException $e) {
            $this->failed($run, $source, $copy, $e->forPeople());
        } catch (RuntimeException $e) {
            $this->failed($run, $source, $copy, $e->getMessage());
        }
    }

    private function runCreate(SiteCopy $run, Site $source, Site $copy): void
    {
        ProvisionSite::dispatchSync($copy);
        $copy->refresh()->load('systemUser');
        if ($copy->status !== SiteStatus::Active) {
            throw new RuntimeException(__('Kopya site oluşturulamadı: :last_error', ['last_error' => $copy->last_error]));
        }
        $pairs = in_array('databases', $run->parts, true) ? $this->copyDatabases($source, $copy) : [];
        $result = $this->copy($run, $source, $copy, true, $pairs, $this->hostsInto($source, $copy), $copy->noindex ? '0' : '');
        // The copy's WordPress is managed like its source's.
        $install = $source->wordpress;
        if ($install !== null && $install->status === WordPressInstall::ACTIVE) {
            $this->wordpress->adopt($copy, $install->path);
        }
        $this->finish($run, null, $result);
    }

    private function runRefresh(SiteCopy $run, Site $source, Site $copy): void
    {
        $pairs = in_array('databases', $run->parts, true) ? $this->copyDatabases($source, $copy) : [];
        $result = $this->copy($run, $source, $copy, in_array('files', $run->parts, true), $pairs, $this->hostsInto($source, $copy), $copy->noindex ? '0' : '');
        $this->cache->purgeQuietly($copy);
        $this->finish($run, null, $result);
    }

    private function runPublish(SiteCopy $run, Site $source, Site $copy): void
    {
        $backup = $this->backups->takeSafety($source, $run->user);
        $run->update(['backup_id' => $backup->id]);
        if ($backup->status !== 'succeeded') {
            $error = $backup->error ?: $backup->items()->where('status', 'failed')->value('error') ?: __('Yedek tamamlanamadı.');
            $this->backups->purge($backup);
            $run->update(['backup_id' => null]);
            $this->finish($run, __('Canlı sitenin yedeği alınamadı, hiçbir şey aktarılmadı: :error', ['error' => $error]));

            return;
        }
        $pairs = [];
        if (in_array('databases', $run->parts, true)) {
            foreach (Database::where('site_id', $copy->id)->whereNotNull('copy_of_id')->orderBy('id')->get() as $database) {
                $live = Database::where('id', $database->copy_of_id)->where('site_id', $source->id)->first();
                if ($live !== null && $live->server === $database->server) {
                    $pairs[] = ['server' => $database->server, 'from' => $database->name, 'to' => $live->name];
                }
            }
        }
        // The live site keeps what it told search engines.
        $result = $this->copy($run, $copy, $source, in_array('files', $run->parts, true), $pairs, $this->hostsBack($copy, $source), 'keep');
        $this->cache->purgeQuietly($source);
        $this->finish($run, null, $result);
    }

    /**
     * Asks the agent to copy $from onto $to.
     *
     * @param  list<array{server: string, from: string, to: string}>  $databases
     * @param  list<array{0: string, 1: string}>  $hosts
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    private function copy(SiteCopy $run, Site $from, Site $to, bool $files, array $databases, array $hosts, string $public): array
    {
        if (! $files && $databases === []) {
            throw new RuntimeException(__('Kopyalanacak bir şey yok: kopyanın kaynağındaki veritabanlarıyla eşleşen veritabanı bulunmuyor.'));
        }
        $wordpress = ($from->wordpress ?? $to->wordpress)?->path;

        return (array) $this->agent->call('site.copy', [
            'run' => $run->run,
            'from' => ['domain' => $from->domain, 'user' => $from->systemUser->name, 'php_version' => $from->php_version],
            'to' => ['domain' => $to->domain, 'user' => $to->systemUser->name, 'php_version' => $to->php_version],
            'files' => $files,
            'databases' => $databases,
            'hosts' => $hosts,
            'wordpress' => (string) $wordpress,
            'public' => $public,
        ], config('serfex.agent.backup_timeout'));
    }

    /**
     * Makes the copy's counterpart of each database of the live site that
     * has none yet; returns the pairs to load.
     *
     * @return list<array{server: string, from: string, to: string}>
     *
     * @throws AgentException
     */
    private function copyDatabases(Site $source, Site $copy): array
    {
        $pairs = [];
        foreach ($this->sourceDatabases($source) as $database) {
            $counterpart = $this->copyOf($copy, $database);
            if ($counterpart === null) {
                $grants = $database->users->mapWithKeys(fn ($u) => [$u->id => $u->pivot->access])->all();
                $counterpart = $this->databases->create($copy, $database->server, $this->databaseName($database), null, null, 'all', $grants);
                $counterpart->update(['copy_of_id' => $database->id]);
            }
            $pairs[] = ['server' => $database->server, 'from' => $database->name, 'to' => $counterpart->name];
        }

        return $pairs;
    }

    /**
     * @return Collection<int, Database>
     */
    private function sourceDatabases(Site $source)
    {
        return Database::where('site_id', $source->id)->with('users')->orderBy('id')->get();
    }

    private function copyOf(Site $copy, Database $database): ?Database
    {
        return Database::where('site_id', $copy->id)->where('copy_of_id', $database->id)->first();
    }

    /**
     * A free name for the copy of $database: shop_wp_copy, shop_wp_copy2…
     */
    public function databaseName(Database $database): string
    {
        for ($i = 1; ; $i++) {
            $suffix = self::DATABASE_SUFFIX.($i > 1 ? $i : '');
            $name = substr($database->name, 0, Databases::MAX_DATABASE - strlen($suffix)).$suffix;
            if (Database::withTrashed()->where('server', $database->server)->where('name', $name)->doesntExist()) {
                return $name;
            }
        }
    }

    /**
     * The live site's names and the copy's, for addresses in settings
     * files and WordPress: www. first, so it does not become www.staging.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function hostsInto(Site $source, Site $copy): array
    {
        $names = array_filter([$source->wwwPair(), $source->domain]);
        usort($names, fn ($a, $b) => strlen($b) <=> strlen($a));

        return array_map(fn ($name) => [$name, $copy->domain], $names);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function hostsBack(Site $copy, Site $source): array
    {
        return [[$copy->domain, $source->primaryHost()]];
    }

    /**
     * A failed run: a copy that was being made goes, with its databases; a
     * publish is undone from the backup taken before it.
     */
    private function failed(SiteCopy $run, Site $source, Site $copy, string $error): void
    {
        if ($run->kind === SiteCopy::CREATE) {
            $copy->update(['status' => SiteStatus::Deleting]);
            DeleteSite::dispatchSync($copy, false, false, true, true);
            $this->finish($run->fresh(), $error);

            return;
        }
        if ($run->kind === SiteCopy::PUBLISH && $run->fresh()->backup_id !== null) {
            $item = $run->backup->items()->where('site_id', $source->id)->where('status', 'succeeded')->first();
            $restore = $item !== null ? $this->restores->start($item, $this->restoreParts($run->parts), false, $run->user, queue: false) : __('Yedek bulunamadı.');
            if ($restore instanceof BackupRestore) {
                $this->restores->run($restore);
                $restore->refresh();
            }
            $undone = $restore instanceof BackupRestore && $restore->status === 'succeeded';
            $run->update(['restore_id' => $restore instanceof BackupRestore ? $restore->id : null]);
            $error = $undone
                ? __(':error Canlı site aktarmadan önceki hâline döndürüldü.', ['error' => $error])
                : __(':error Canlı site geri alınamadı; Yedekler sayfasından aktarmadan önceki yedeği geri yükleyin.', ['error' => $error]);
        }
        $this->finish($run, $error);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(SiteCopy $run, ?string $error, array $result = []): void
    {
        $run->update([
            'status' => $error === null ? 'succeeded' : 'failed',
            'error' => $error,
            'notes' => $result['notes'] ?? null,
            'finished_at' => now(),
        ]);
    }

    /**
     * Restore parts for copy parts.
     *
     * @param  list<string>  $parts
     * @return list<string>
     */
    private function restoreParts(array $parts): array
    {
        return array_values(array_intersect(['files', 'databases'], $parts));
    }

    private function siteRefusal(Site $site): ?string
    {
        if ($site->status !== SiteStatus::Active) {
            return __(':domain şu an hazır değil; biraz sonra yeniden deneyin.', ['domain' => $site->domain]);
        }
        if ($site->isSuspended()) {
            return __('Hesap askıya alınmış.');
        }
        $busy = SiteCopy::where(fn ($q) => $q->where('source_id', $site->copy_of_id ?? $site->id)->orWhere('site_id', $site->id))
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($busy) {
            return __('Bu site için bir kopyalama sürüyor; bitmesini bekleyin.');
        }

        return null;
    }

    /**
     * Why the owner's package has no room for one more site and $databases
     * more databases, or null.
     */
    private function quotaRefusal(Site $site, int $databases, bool $sites = true): ?string
    {
        $customer = $site->customer();
        if ($customer === null) {
            return null;
        }
        if ($sites && ($refusal = $this->quotas->refusal($customer, 'sites')) !== null) {
            return $refusal;
        }
        if ($databases === 0) {
            return null;
        }
        if (($refusal = $this->quotas->refusal($customer, 'databases')) !== null) {
            return $refusal;
        }
        $report = $this->quotas->report($customer)['databases'];
        if ($report['limit'] !== null && $report['limit'] < $report['used'] + $databases) {
            return __('Kopya :count veritabanı daha ister; paketin veritabanı sınırı buna yetmiyor (:text).', ['count' => $databases, 'text' => $report['text']]);
        }

        return null;
    }
}
