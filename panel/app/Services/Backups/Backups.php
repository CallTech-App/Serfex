<?php

namespace App\Services\Backups;

use App\Enums\SiteStatus;
use App\Jobs\PurgeBackup;
use App\Jobs\RunBackup;
use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Models\BackupTarget;
use App\Models\Database;
use App\Models\MailDomain;
use App\Models\PanelUpdate;
use App\Models\Site;
use App\Models\SiteMigration;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Customers\Quotas;
use App\Support\Bytes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Yedekler: whole-server and site backups taken by the agent, on this
 * server's disk and on a remote target, on a schedule (full and
 * incremental, with retention) or on demand; deleted with Undo.
 *
 * A whole-server backup is one archive per site plus one for the panel.
 * Scheduled ones form chains: an incremental archive holds what changed
 * since the archive of the same site in the previous scheduled backup,
 * back to a full one, and a restore unpacks the whole chain.
 */
class Backups
{
    /** Minutes a deleted backup can still be brought back. */
    public const UNDO_MINUTES = 10;

    /** Days the backup taken before a restore is kept. */
    public const SAFETY_DAYS = 7;

    /** Hours a remote-only backup fetched to this server stays here. */
    public const CACHE_HOURS = 24;

    /** Days failed backups stay listed. */
    public const FAILED_DAYS = 30;

    public const BUSY = 'Başka bir yedekleme ya da geri yükleme sürüyor. Bitince yeniden deneyin.';

    public const UPDATING = 'Serfex güncelleniyor. Bitince yeniden deneyin.';

    /** Days the panel backups taken before updates are kept. */
    public const UPDATE_DAYS = 30;

    /**
     * Site backups the panel takes for itself, before a restore and before
     * a deletion: on this server only, outside the queue and the package.
     */
    public const LOCAL_TRIGGERS = ['safety', 'delete'];

    /** Room left free on the backup disk besides the archive itself. */
    public const DELETE_MARGIN = 1 << 30;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly BackupSettings $settings,
        private readonly SiteSnapshot $snapshot,
        private readonly PanelDump $panel,
    ) {}

    /**
     * True while a backup, a restore or a move from another panel is
     * queued or running; the agent takes one at a time.
     */
    public function busy(): bool
    {
        return Backup::whereIn('status', ['queued', 'running'])->exists()
            || BackupRestore::whereIn('status', ['queued', 'running'])->exists()
            || SiteMigration::whereIn('status', SiteMigration::RUNNING)->exists();
    }

    /**
     * What needs a look, newest first, for the menu and the dashboard: the
     * last whole-server backup failed or half done, the schedule stuck,
     * a target that fails its test.
     *
     * @return list<array{text: string, level: string}>
     */
    public function problems(): array
    {
        $out = [];
        $last = Backup::with('items')->where('scope', 'server')->whereIn('status', ['succeeded', 'partial', 'failed'])->latest('id')->first();
        if ($last?->status === 'failed') {
            $out[] = ['text' => __('Son sunucu yedeği alınamadı: :error', ['error' => Str::limit((string) $last->error, 120)]), 'level' => 'crit'];
        } elseif ($last?->status === 'partial') {
            $failed = $last->items->where('status', 'failed');
            $out[] = ['text' => $failed->isNotEmpty()
                ? __('Son sunucu yedeğinde :count arşiv alınamadı: :join', ['count' => $failed->count(), 'join' => $failed->pluck('name')->take(3)->join(', ')])
                : Str::limit((string) $last->error, 140), 'level' => 'warn'];
        }
        $s = $this->settings->schedule();
        $slot = $this->settings->lastSlot(now());
        if ($s['enabled'] && $slot !== null && $slot->lt(now()->subHours(3))) {
            $taken = Backup::where('scope', 'server')->where('trigger', 'scheduled')->where('created_at', '>=', $slot->copy()->subMinutes(5))->exists();
            if (! $taken && Site::where('created_at', '<', $slot)->exists()) {
                $out[] = ['text' => __(':format zamanlanmış yedeği başlamadı; panelin zamanlayıcısı ve "backups" kuyruğu çalışıyor mu?', ['format' => $slot->copy()->timezone(config('serfex.timezone'))->format('d.m H:i')]), 'level' => 'warn'];
            }
        }
        if (($queued = Backup::where('status', 'queued')->oldest('id')->first()) !== null && $queued->created_at->lt(now()->subMinutes(10))) {
            $out[] = ['text' => __('Bir yedek 10 dakikadır kuyrukta bekliyor; "php artisan queue:work backups --queue=backups" çalışıyor mu?'), 'level' => 'warn'];
        }
        foreach (BackupTarget::where('status', 'failed')->get() as $t) {
            $out[] = ['text' => __(':name hedefine ulaşılamıyor: :last_error', ['name' => $t->name, 'last_error' => Str::limit((string) $t->last_error, 100)]), 'level' => 'warn'];
        }

        return $out;
    }

    /**
     * Queues a whole-server backup. Returns why not, as text, when it
     * cannot start.
     */
    public function startServer(?User $user, string $trigger = 'manual'): Backup|string
    {
        if ($this->busy()) {
            return __(self::BUSY);
        }
        if (PanelUpdate::running()->exists()) {
            return __(self::UPDATING);
        }
        $type = 'full';
        $base = null;
        if ($trigger === 'scheduled') {
            [$type, $base] = $this->scheduledType(now());
        }
        $backup = $this->record('server', $trigger, $user, null, $type, $base, $this->settings->target());
        foreach ($this->serverSites() as $site) {
            $backup->items()->create(['name' => $site->domain, 'site_id' => $site->id, 'customer_id' => $site->systemUser->customer_id, 'status' => 'pending']);
        }
        $backup->items()->create(['name' => 'panel', 'status' => 'pending']);
        RunBackup::dispatch($backup);

        return $backup;
    }

    /**
     * Queues a backup of one site. Customers back up within their
     * package; the panel's own (before a restore or a deletion) stay on
     * this server and are taken by the caller.
     */
    public function startSite(Site $site, ?User $user, string $trigger = 'manual'): Backup|string
    {
        $local = in_array($trigger, self::LOCAL_TRIGGERS, true);
        if (! $local) {
            if ($this->busy()) {
                return __(self::BUSY);
            }
            if (PanelUpdate::running()->exists()) {
                return __(self::UPDATING);
            }
            if (($refusal = $this->refusal($user, $site)) !== null) {
                return $refusal;
            }
        }
        $site->loadMissing('systemUser.customer');
        $customer = $user?->isCustomer() ? $user->customer : null;
        $backup = $this->record('site', $trigger, $user, $customer, 'full', null, $local ? null : $this->settings->target());
        $backup->items()->create(['name' => $site->domain, 'site_id' => $site->id, 'customer_id' => $site->systemUser->customer_id, 'status' => 'pending']);
        if (! $local) {
            RunBackup::dispatch($backup);
        }
        Quotas::forget();

        return $backup;
    }

    /**
     * Why $user may not back up $site now, or null.
     */
    public function refusal(?User $user, Site $site): ?string
    {
        if ($site->status === SiteStatus::Deleting || $site->provisioned_at === null) {
            return __('Site henüz hazır değil.');
        }
        if ($user === null || $user->is_admin) {
            return null;
        }
        if (! $user->allows('backups')) {
            return __('Paketiniz yedeklemeyi içermiyor.');
        }

        return app(Quotas::class)->refusal($user->customer, 'backups');
    }

    /**
     * Sites a whole-server backup takes: every site in service whose
     * customer's package includes backups and that is not left out.
     *
     * @return Collection<int, Site>
     */
    public function serverSites(): Collection
    {
        $skip = $this->settings->schedule()['skip_sites'];

        return Site::with('systemUser.customer.package')
            ->whereNotNull('provisioned_at')
            ->where('status', '!=', SiteStatus::Deleting)
            ->whereDoesntHave('preview')
            ->whereNotIn('domain', $skip)
            ->orderBy('domain')
            ->get()
            ->filter(fn (Site $s) => $s->customer()?->package === null || $s->customer()->package->allows('backups'))
            ->values();
    }

    /**
     * Takes the backup now and records the outcome (RunBackup calls it).
     */
    public function take(Backup $backup): void
    {
        $backup->update(['status' => 'running', 'started_at' => now()]);
        $items = $backup->items()->with('site.systemUser')->get();
        $warnings = [];
        $specs = [];
        $homes = [];
        foreach ($items as $item) {
            if ($item->isPanel()) {
                $panel = $this->panel->upload();
                $warnings['panel'] = $panel['warnings'];
                $specs[] = ['name' => 'panel', 'uploads' => (object) $panel['uploads']];

                continue;
            }
            $site = $item->site;
            if ($site === null || $site->systemUser === null) {
                $item->update(['status' => 'failed', 'error' => __('Site yedek alınmadan önce silindi.')]);

                continue;
            }
            // The account's other files once, with its first site.
            $home = ! isset($homes[$site->system_user_id]) && ($backup->scope === 'server' || $site->systemUser->sites()->orderBy('domain')->value('id') === $site->id);
            $homes[$site->system_user_id] = true;
            $spec = $this->snapshot->item($site, $home);
            $item->update(['meta' => $spec['meta']]);
            $specs[] = $spec;
        }
        if ($specs === []) {
            $this->fail($backup, __('Yedeklenecek bir şey kalmadı.'));

            return;
        }
        $target = $backup->target;
        $call = [
            'key' => $backup->key,
            'run' => $backup->run,
            // To the agent a panel backup is a whole-server one of the panel alone.
            'scope' => $backup->scope === 'panel' ? 'server' : $backup->scope,
            'server' => BackupTarget::serverName(),
            'incremental' => $backup->type === 'incremental',
            'chain' => $this->chained($backup),
            'base' => (string) $backup->base?->key,
            'items' => $specs,
            'keep_local' => $target === null || (bool) $this->settings->schedule()['keep_local'],
            'exclude' => array_values($this->settings->schedule()['exclude']),
        ];
        if ($target !== null) {
            $call['target'] = $target->agentSpec($backup->remote_prefix);
            if ($backup->encrypted) {
                $call['passphrase'] = $backup->passphrase;
            }
        }
        try {
            $result = $this->agent->call('backup.create', $call, $backup->scope === 'server' ? config('serfex.agent.server_backup_timeout') : config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            $this->fail($backup, $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamadı.') : ($e->reason ?: $e->getMessage()));

            return;
        }
        $failed = 0;
        foreach ((array) ($result['items'] ?? []) as $r) {
            $item = $items->firstWhere('name', $r['name'] ?? '');
            if ($item === null) {
                continue;
            }
            $error = ($r['error'] ?? '') ?: null;
            $failed += $error !== null ? 1 : 0;
            $item->update([
                'status' => $error === null ? 'succeeded' : 'failed',
                'error' => $error,
                'size' => (int) ($r['size'] ?? 0),
                'parts' => (array) ($r['parts'] ?? []),
                'incremental' => array_values((array) ($r['incremental'] ?? [])),
                'skipped' => (array) ($r['skipped'] ?? []),
                'warnings' => array_values(array_merge((array) ($r['warnings'] ?? []), $warnings[$item->name] ?? [])),
            ]);
        }
        $backup->items()->where('status', 'pending')->update(['status' => 'failed', 'error' => __('Ajan bu arşivi almadı.')]);
        $remoteError = ($result['remote_error'] ?? '') ?: null;
        $backup->update([
            'status' => ($failed > 0 || $remoteError !== null || $backup->items()->where('status', 'failed')->exists()) ? 'partial' : 'succeeded',
            'size' => (int) ($result['size'] ?? 0),
            'seconds' => (int) ($result['seconds'] ?? 0),
            'local' => (bool) ($result['local'] ?? true),
            'remote' => (bool) ($result['remote'] ?? false),
            'encrypted' => (bool) ($result['encrypted'] ?? false),
            'error' => $remoteError !== null ? __('Uzak hedefe gönderilemedi, yedek bu sunucuda saklandı: :remote_error', ['remote_error' => $remoteError]) : null,
            'finished_at' => now(),
        ]);
        if ($backup->trigger === 'scheduled') {
            $this->prune();
        }
        Quotas::forget();
    }

    /**
     * Records a backup whose job stopped half-way (it failed, or its worker
     * was stopped) as failed.
     */
    public function abandon(Backup $backup, ?string $error = null): void
    {
        $backup->items()->where('status', 'pending')->update(['status' => 'failed', 'error' => __('Yedekleme yarıda kaldı.')]);
        $backup->update(['status' => 'failed', 'error' => $error ?? __('Yedekleme yarıda kaldı.'), 'finished_at' => now()]);
    }

    /**
     * Takes a backup of the panel alone (its database, settings and the
     * agent's state) now, on this server, before Serfex updates itself;
     * returns it.
     */
    public function takePanel(?User $user): Backup
    {
        $backup = $this->record('panel', 'update', $user, null, 'full', null, null);
        $backup->items()->create(['name' => 'panel', 'status' => 'pending']);
        $this->take($backup);

        return $backup->refresh();
    }

    /**
     * Takes a site backup now, for a restore's Undo; returns it.
     */
    public function takeSafety(Site $site, ?User $user): Backup
    {
        $backup = $this->startSite($site, $user, 'safety');
        $this->take($backup);

        return $backup->refresh();
    }

    /**
     * Takes a site backup now, before the site is deleted, so the deletion
     * can be undone from Yedekler; returns it.
     */
    public function takeBeforeDelete(Site $site, ?User $user): Backup
    {
        $backup = $this->startSite($site, $user, 'delete');
        $this->take($backup);

        return $backup->refresh();
    }

    /**
     * Why the backup before deleting $site would not fit on this server's
     * disk, or null. The site's files, databases and mail are measured
     * from time to time; the archive is compressed, so this errs on the
     * safe side. Null too when the agent cannot tell.
     */
    public function noRoomBeforeDelete(Site $site): ?string
    {
        $free = $this->status()['free'] ?? null;
        if ($free === null) {
            return null;
        }
        $need = $this->siteBytes($site);
        if ($free >= $need + self::DELETE_MARGIN) {
            return null;
        }

        return __('Yedek için sunucuda yeterli boş alan yok (yaklaşık :need gerekiyor, :free boş).', [
            'need' => Bytes::format($need + self::DELETE_MARGIN),
            'free' => Bytes::format((int) $free),
        ]);
    }

    /**
     * True when the site's system account is no longer on this server (a
     * migration that stopped half way, a deletion cut off after the
     * account went): its backup then holds everything but the files.
     * False when the agent cannot tell.
     */
    public function accountGone(Site $site): bool
    {
        try {
            return ($this->agent->call('site.account.status', ['user' => $site->systemUser->name])['exists'] ?? true) === false;
        } catch (AgentException) {
            return false;
        }
    }

    /**
     * The measured size of a site's files, databases and mail.
     */
    public function siteBytes(Site $site): int
    {
        $mail = MailDomain::where('name', $site->domain)->first();

        return (int) $site->disk_bytes
            + (int) Database::where('site_id', $site->id)->sum('size_bytes')
            + ($mail ? (int) $mail->mailboxes()->sum('usage_bytes') : 0);
    }

    /**
     * When a backup taken before a deletion goes, or null for others.
     */
    public function expiresAt(Backup $backup): ?Carbon
    {
        if ($backup->trigger !== 'delete') {
            return null;
        }

        return $backup->created_at->copy()->addDays(max(1, (int) $this->settings->schedule()['delete_days']));
    }

    /**
     * True when the backup moves the incremental chain on: scheduled
     * whole-server backups only, so manual ones never break it.
     */
    public function chained(Backup $backup): bool
    {
        return $backup->scope === 'server' && $backup->trigger === 'scheduled';
    }

    /**
     * Whether the scheduled backup at $now is full or incremental, and the
     * backup an incremental continues from.
     *
     * @return array{0: string, 1: ?Backup}
     */
    public function scheduledType(Carbon $now): array
    {
        $previous = Backup::where('scope', 'server')->where('trigger', 'scheduled')->usable()->latest('id')->first();
        $slot = $this->settings->lastSlot($now) ?? $now;
        if ($previous === null || $this->settings->fullOn($slot)) {
            return ['full', null];
        }

        return ['incremental', $previous];
    }

    /**
     * True when the scheduled backup is due at $now (and none was taken
     * for that time yet).
     */
    public function due(Carbon $now): bool
    {
        if (! $this->settings->schedule()['enabled']) {
            return false;
        }
        $slot = $this->settings->lastSlot($now);
        if ($slot === null) {
            return false;
        }

        return Backup::where('scope', 'server')->where('trigger', 'scheduled')->where('created_at', '>=', $slot)->doesntExist();
    }

    /**
     * The archives a restore of $item unpacks, oldest first: back to the
     * full archive of the same site an incremental one continues from.
     * Returns why not, as text, when part of the chain is gone.
     *
     * @return list<BackupItem>|string
     */
    public function chain(BackupItem $item): array|string
    {
        $chain = [$item];
        $current = $item;
        $item->loadMissing('backup');
        while ($current->isIncremental()) {
            $previous = BackupItem::with('backup')
                ->where('name', $item->name)
                ->where('status', 'succeeded')
                ->where('backup_id', '<', $current->backup_id)
                // Own scheduled backups, or those adopted from the same
                // other server.
                ->whereHas('backup', fn ($q) => $q->where('scope', 'server')->whereIn('status', ['succeeded', 'partial', 'deleted'])
                    ->when($item->backup->trigger === 'import',
                        fn ($q) => $q->where('trigger', 'import')->where('source_host', $item->backup->source_host)->where('remote_prefix', $item->backup->remote_prefix),
                        fn ($q) => $q->where('trigger', 'scheduled')))
                ->orderByDesc('backup_id')
                ->first();
            if ($previous === null || ! in_array($previous->backup->status, ['succeeded', 'partial'], true)) {
                return __('Bu artımlı yedeğin dayandığı önceki yedek artık yok; geri yüklenemez.');
            }
            array_unshift($chain, $previous);
            $current = $previous;
            if (count($chain) > 400) {
                return __('Yedek zinciri çok uzun.');
            }
        }

        return $chain;
    }

    /**
     * An archive as the agent's restore and fetch take it.
     *
     * @return array<string, mixed>
     */
    public function chainRef(BackupItem $item): array
    {
        $backup = $item->backup;
        $ref = ['key' => $backup->key, 'name' => $item->name];
        if ($backup->remote && $backup->target !== null) {
            $ref['target'] = $backup->target->agentSpec($backup->remote_prefix);
        }

        return $ref;
    }

    /**
     * Backups that continue from $backup and go with it: the later
     * scheduled backups whose archives need it.
     *
     * @return Collection<int, Backup>
     */
    public function dependents(Backup $backup): Collection
    {
        if (! $this->chained($backup)) {
            return collect();
        }
        $later = Backup::with('items')->where('scope', 'server')->where('trigger', 'scheduled')->usable()
            ->where('id', '>', $backup->id)->orderBy('id')->limit(400)->get();

        return $later->filter(function (Backup $b) use ($backup) {
            foreach ($b->items as $item) {
                if (! $item->isIncremental()) {
                    continue;
                }
                $chain = $this->chain($item);
                if (is_array($chain) && collect($chain)->contains(fn (BackupItem $i) => $i->backup_id === $backup->id)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Hides a backup (and those that continue from it) and removes them
     * from the server and the target once Undo has expired. Returns the
     * ids hidden.
     *
     * @return list<int>
     */
    public function delete(Backup $backup): array
    {
        $all = collect([$backup])->merge($this->dependents($backup));
        foreach ($all as $b) {
            $b->update(['status' => 'deleted']);
            PurgeBackup::dispatch($b)->delay(now()->addMinutes(self::UNDO_MINUTES));
        }
        Quotas::forget();

        return $all->pluck('id')->all();
    }

    /**
     * @param  list<int>  $ids
     */
    public function undoDelete(array $ids): int
    {
        $n = 0;
        foreach (Backup::whereIn('id', $ids)->where('status', 'deleted')->get() as $b) {
            $failed = $b->items()->where('status', 'failed')->exists();
            $b->update(['status' => ($failed || $b->error) ? 'partial' : 'succeeded']);
            $n++;
        }
        Quotas::forget();

        return $n;
    }

    /**
     * Removes a backup from the server, the target and the panel now.
     */
    public function purge(Backup $backup): void
    {
        $spec = ['key' => $backup->key, 'local' => true];
        if ($backup->remote && $backup->target !== null) {
            $spec['target'] = $backup->target->agentSpec($backup->remote_prefix);
        }
        try {
            $this->agent->call('backup.delete', $spec, config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            // Kept and listed as failed rather than leaving files nobody sees.
            $backup->update(['status' => 'failed', 'error' => 'Silinemedi: '.($e->reason ?: $e->getMessage())]);

            return;
        }
        $backup->delete();
        Quotas::forget();
    }

    /**
     * Retention: the newest scheduled backup of each of the last
     * keep_daily days, keep_weekly weeks and keep_monthly months stay, with
     * the archives they continue from; older ones go. Backups taken before
     * restores go after SAFETY_DAYS, those taken before a site was deleted
     * after the days set in the schedule, failed ones after FAILED_DAYS.
     * Manual backups stay until deleted.
     */
    public function prune(): void
    {
        $s = $this->settings->schedule();
        $scheduled = Backup::with('items')->where('scope', 'server')->where('trigger', 'scheduled')->usable()->orderByDesc('created_at')->get();
        $keep = [];
        $seen = ['d' => [], 'w' => [], 'm' => []];
        foreach ($scheduled as $b) {
            foreach (['d' => ['Y-m-d', 'keep_daily'], 'w' => ['o-W', 'keep_weekly'], 'm' => ['Y-m', 'keep_monthly']] as $k => [$format, $setting]) {
                $period = $b->created_at->format($format);
                if (! in_array($period, $seen[$k], true) && count($seen[$k]) < max(0, (int) $s[$setting])) {
                    $seen[$k][] = $period;
                    $keep[$b->id] = true;
                }
            }
        }
        // The newest one always stays.
        if ($scheduled->isNotEmpty()) {
            $keep[$scheduled->first()->id] = true;
        }
        // And everything the kept ones continue from.
        foreach ($scheduled->whereIn('id', array_keys($keep)) as $b) {
            foreach ($b->items as $item) {
                if ($item->isIncremental() && is_array($chain = $this->chain($item))) {
                    foreach ($chain as $link) {
                        $keep[$link->backup_id] = true;
                    }
                }
            }
        }
        foreach ($scheduled->reject(fn (Backup $b) => isset($keep[$b->id])) as $b) {
            $this->purge($b);
        }
        foreach (Backup::where('trigger', 'safety')->where('created_at', '<', now()->subDays(self::SAFETY_DAYS))->whereNot('status', 'running')->get() as $b) {
            $this->purge($b);
        }
        foreach (Backup::where('trigger', 'update')->where('created_at', '<', now()->subDays(self::UPDATE_DAYS))->whereNot('status', 'running')->get() as $b) {
            $this->purge($b);
        }
        foreach (Backup::where('trigger', 'delete')->where('created_at', '<', now()->subDays(max(1, (int) $s['delete_days'])))->whereNot('status', 'running')->get() as $b) {
            $this->purge($b);
        }
        foreach (Backup::where('status', 'failed')->where('created_at', '<', now()->subDays(self::FAILED_DAYS))->get() as $b) {
            $this->purge($b);
        }
        // With a remote target and "keep the newest here", older local
        // copies go.
        if ($s['target_id'] !== null && $s['keep_local']) {
            $newest = $scheduled->first(fn (Backup $b) => $b->local && $b->remote);
            foreach (Backup::where('local', true)->where('remote', true)->where('trigger', 'scheduled')->when($newest, fn ($q) => $q->whereKeyNot($newest->id))->get() as $b) {
                $this->dropLocal($b);
            }
        }
    }

    /**
     * Copies a remote-only backup's archives to this server, for a
     * download. Returns why not, as text, on failure.
     */
    public function fetch(Backup $backup, ?string $name = null): ?string
    {
        if ($backup->onServer()) {
            return null;
        }
        if ($backup->target === null) {
            return __('Yedeğin hedefi artık yok.');
        }
        $names = $name !== null ? [$name] : $backup->items()->where('status', 'succeeded')->pluck('name')->all();
        try {
            $this->agent->call('backup.fetch', [
                'key' => $backup->key,
                'names' => $names,
                'target' => $backup->target->agentSpec($backup->remote_prefix),
                'passphrase' => $backup->encrypted ? (string) $backup->passphrase : '',
            ], config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            return $e->getCode() === -32006 ? __(self::BUSY) : __('Hedeften alınamadı: :reason', ['reason' => ($e->reason ?: $e->getMessage())]);
        }
        $backup->update(['cached_at' => now()]);

        return null;
    }

    /**
     * Removes copies fetched from targets once CACHE_HOURS have passed.
     */
    public function dropCaches(): void
    {
        foreach (Backup::whereNotNull('cached_at')->where('local', false)->where('cached_at', '<', now()->subHours(self::CACHE_HOURS))->get() as $b) {
            $this->dropLocal($b);
        }
    }

    /**
     * Removes the copy on this server of a backup that is on its target.
     */
    private function dropLocal(Backup $backup): void
    {
        try {
            $this->agent->call('backup.delete', ['key' => $backup->key, 'local' => true], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            report($e);

            return;
        }
        $backup->update(['local' => false, 'cached_at' => null]);
    }

    /**
     * Tests a target: connect, write, read back, delete. An SFTP server's
     * host key is learned on the first test and checked from then on.
     * Returns why it failed, or null.
     */
    public function test(BackupTarget $target): ?string
    {
        try {
            $result = $this->agent->call('backup.target.test', $target->agentSpec(), 180);
        } catch (AgentException $e) {
            $why = $e instanceof AgentUnavailableException ? __('Sunucu ajanına ulaşılamıyor.') : ($e->reason ?: $e->getMessage());
            $target->update(['status' => 'failed', 'last_error' => $why, 'tested_at' => now()]);

            return $why;
        }
        if ($target->type === 'sftp' && ($result['host_key'] ?? '') !== '') {
            $target->config = [...$target->config, 'host_key' => $result['host_key'], 'fingerprint' => $result['fingerprint'] ?? null];
        }
        $target->fill(['status' => 'ok', 'last_error' => null, 'tested_at' => now()])->save();

        return null;
    }

    /**
     * The agent's view of backups: disk use, free space and its SSH key.
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        try {
            return (array) $this->agent->call('backup.status');
        } catch (AgentException) {
            return null;
        }
    }

    /**
     * Records a backup; remote copies are encrypted when the schedule says
     * so, with the passphrase of the moment kept with it.
     */
    private function record(string $scope, string $trigger, ?User $user, mixed $customer, string $type, ?Backup $base, ?BackupTarget $target): Backup
    {
        $random = Str::lower(Str::random(10));
        $encrypt = $target !== null && (bool) $this->settings->schedule()['encrypt'];

        return Backup::create([
            'key' => now()->format('Ymd-His').'-'.$random,
            'scope' => $scope,
            'type' => $type,
            'trigger' => $trigger,
            'base_id' => $base?->id,
            'target_id' => $target?->id,
            'remote_prefix' => $target?->prefix(),
            'encrypted' => $encrypt,
            'passphrase' => $encrypt ? $this->settings->passphrase() : null,
            'local' => true,
            'status' => 'queued',
            'run' => 'backup-'.$random,
            'user_id' => $user?->id,
            'customer_id' => $customer?->id,
        ]);
    }

    private function fail(Backup $backup, string $why): void
    {
        $backup->items()->where('status', 'pending')->update(['status' => 'failed', 'error' => $why]);
        $backup->update(['status' => 'failed', 'error' => $why, 'local' => false, 'finished_at' => now()]);
    }
}
