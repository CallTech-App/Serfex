<?php

namespace App\Services\Backups;

use App\Enums\CertificateStatus;
use App\Enums\MailDomainStatus;
use App\Enums\SiteStatus;
use App\Enums\ZoneStatus;
use App\Jobs\ProvisionSite;
use App\Jobs\RestoreBackup;
use App\Jobs\SyncDnsZone;
use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Components\Components;
use App\Services\Customers\Quotas;
use App\Services\Dns\Zones;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts a site back from a backup archive, in whole or in part: files,
 * databases (with their users' passwords), mail, DNS records and SSL
 * certificates. The current state is backed up first so the restore can
 * be undone. A site that no longer exists (deleted, or a backup from
 * another server) is created again from the panel's description in the
 * archive.
 */
class Restores
{
    /** Agent parts behind each restorable part. */
    private const AGENT_PARTS = [
        'files' => ['files', 'home'],
        'databases' => ['databases', 'laravel'],
        'mail' => ['mail'],
        'certificates' => ['certificates'],
    ];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Backups $backups,
    ) {}

    /**
     * Queues a restore. Returns why not, as text, when it cannot start.
     *
     * @param  list<string>  $parts
     */
    public function start(BackupItem $item, array $parts, bool $safety, ?User $user, bool $provision = false, bool $queue = true): BackupRestore|string
    {
        $item->loadMissing('backup', 'site.systemUser');
        if ($item->isPanel() || $item->status !== 'succeeded' || ! $item->backup->isUsable()) {
            return 'Bu yedek geri yüklenemez.';
        }
        $parts = array_values(array_intersect(array_keys(Backup::RESTORE_PARTS), $parts, $item->restorable()));
        if ($parts === []) {
            return 'Geri yüklenecek en az bir şey seçin.';
        }
        if ($this->backups->busy()) {
            return Backups::BUSY;
        }
        $site = $this->siteFor($item);
        if (($refusal = $this->refusal($user, $item, $site, $parts)) !== null) {
            return $refusal;
        }
        if (is_string($chain = $this->backups->chain($item))) {
            return $chain;
        }
        if ($site === null && $item->meta !== null && empty($item->meta['site'])) {
            return 'Site artık yok ve yedekte sitenin tanımı bulunmuyor; önce siteyi oluşturun.';
        }
        $restore = BackupRestore::create([
            'backup_item_id' => $item->id,
            'site_id' => $site?->id,
            'domain' => $item->name,
            'parts' => $parts,
            'status' => 'queued',
            'run' => 'restore-'.Str::lower(Str::random(12)),
            'user_id' => $user?->id,
            // provision: the site is in the panel but not on this server
            // (a rebuilt panel); it is set up before its files come back.
            'result' => ['safety' => $safety && $site !== null && ! $provision, 'provision' => $provision],
        ]);
        if ($queue) {
            RestoreBackup::dispatch($restore);
        }

        return $restore;
    }

    /**
     * The site an archive is restored into: its own, or one with its
     * domain; null when it has to be created again.
     */
    public function siteFor(BackupItem $item): ?Site
    {
        return $item->site ?? Site::with('systemUser')->where('domain', $item->name)->first();
    }

    /**
     * Why $user may not restore those parts of $item, or null. Customers
     * restore their own sites (or recreate one of theirs that was
     * deleted), within their package.
     *
     * @param  list<string>  $parts
     */
    public function refusal(?User $user, BackupItem $item, ?Site $site, array $parts = []): ?string
    {
        if ($site !== null && in_array($site->status, [SiteStatus::Deleting, SiteStatus::Provisioning], true)) {
            return 'Site şu an değişiyor; biraz sonra yeniden deneyin.';
        }
        if ($user === null || $user->is_admin) {
            return null;
        }
        if (! $user->allows('backups')) {
            return 'Paketiniz yedeklemeyi içermiyor.';
        }
        if ($site !== null) {
            $own = $user->canSeeSite($site);
        } else {
            // A deleted site of theirs comes back to their own account.
            $name = $item->meta['account']['name'] ?? null;
            $account = $name !== null ? SystemUser::where('name', $name)->first() : null;
            $own = $item->customer_id === $user->customer_id && $name !== null
                && ($account !== null ? $account->customer_id === $user->customer_id : $name === $user->customer?->username);
        }
        if (! $own) {
            return 'Bu yedek sizin sitelerinizden birine ait değil.';
        }
        if ($site === null && ($refusal = app(Quotas::class)->refusal($user->customer, 'sites')) !== null) {
            return $refusal;
        }
        if (in_array('mail', $parts, true) && ! $user->allows('mail')) {
            return 'Paketiniz e-postayı içermiyor.';
        }
        if (in_array('dns', $parts, true) && ! $user->allows('dns')) {
            return 'Paketiniz DNS düzenlemeyi içermiyor.';
        }

        return null;
    }

    /**
     * Runs the restore (RestoreBackup calls it).
     */
    public function run(BackupRestore $restore): void
    {
        $restore->update(['status' => 'running']);
        $item = $restore->item()->with('backup.target')->first();
        if ($item === null) {
            $this->fail($restore, 'Yedek silinmiş.');

            return;
        }
        $parts = $restore->parts;
        try {
            $meta = $this->meta($item);
        } catch (AgentException $e) {
            $this->fail($restore, 'Yedek okunamadı: '.($e->reason ?: $e->getMessage()));

            return;
        }
        if ($meta === null) {
            $this->fail($restore, 'Yedek okunamadı.');

            return;
        }
        $user = $restore->user_id ? User::find($restore->user_id) : null;
        try {
            $site = $this->siteFor($item);
            if ($site === null) {
                if (empty($meta['site'])) {
                    $this->fail($restore, 'Site artık yok ve yedekte sitenin tanımı bulunmuyor; önce siteyi oluşturun.');

                    return;
                }
                $site = $this->recreate($item, $meta);
                $restore->update(['site_id' => $site->id, 'recreated' => true]);
            } elseif ($restore->result['provision'] ?? false) {
                $this->provision($site);
            } elseif ($restore->result['safety'] ?? false) {
                $safety = $this->backups->takeSafety($site, $user);
                if (! $safety->isUsable()) {
                    $this->fail($restore, 'Geri yüklemeden önce sitenin şimdiki hâli yedeklenemedi, hiçbir şey değiştirilmedi: '.($safety->error ?: $safety->items()->value('error')));

                    return;
                }
                $restore->update(['safety_backup_id' => $safety->id]);
            }
            $site->loadMissing('systemUser');

            $agentParts = collect($parts)->flatMap(fn ($p) => self::AGENT_PARTS[$p] ?? [])->values()->all();
            $result = ['restored' => [], 'skipped' => []];
            if ($agentParts !== []) {
                $users = in_array('databases', $parts, true) ? $this->prepareDatabases($site, $meta) : [];
                $chain = $this->backups->chain($item);
                if (is_string($chain)) {
                    $this->fail($restore, $chain);

                    return;
                }
                $app = $site->laravelApp;
                $result = (array) $this->agent->call('backup.restore', [
                    'run' => $restore->run,
                    'chain' => array_map(fn (BackupItem $i) => $this->backups->chainRef($i), $chain),
                    'passphrase' => (string) ($item->backup->passphrase ?? ''),
                    'parts' => $agentParts,
                    'site' => ['domain' => $site->domain, 'user' => $site->systemUser->name],
                    'databases' => collect($meta['databases'] ?? [])->map(fn ($d) => ['server' => $d['server'], 'name' => $d['name']])->values()->all(),
                    'database_users' => $users,
                    'laravel' => $app !== null ? [$app->agentApp()] : [],
                ], config('servik.agent.server_backup_timeout'));
                $this->cacheFetched($chain);
            }
            if (in_array('mail', $parts, true)) {
                $this->restoreMail($site, $meta);
            }
            if (in_array('dns', $parts, true)) {
                $this->restoreDns($site, $meta);
            }
            if (in_array('certificates', $parts, true)) {
                $this->restoreCertificates($site, $meta, (array) ($result['skipped'] ?? []));
            }
            if ($restore->recreated || ($restore->result['provision'] ?? false) || in_array('certificates', $parts, true)) {
                ProvisionSite::dispatchSync($site->fresh());
            }
        } catch (AgentException $e) {
            $this->fail($restore, $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamadı.' : ($e->getCode() === -32006 ? Backups::BUSY : ($e->reason ?: $e->getMessage())));

            return;
        } catch (Throwable $e) {
            report($e);
            $this->fail($restore, $e->getMessage());

            return;
        }
        Quotas::forget();
        $restore->update([
            'status' => 'succeeded',
            'result' => [...($restore->result ?? []), 'restored' => $result['restored'] ?? [], 'skipped' => (array) ($result['skipped'] ?? [])],
            'finished_at' => now(),
        ]);
    }

    /**
     * The panel's description of the site in the archive. Backups found on
     * a target are read the first time they are used.
     *
     * @return array<string, mixed>|null
     */
    public function meta(BackupItem $item): ?array
    {
        if ($item->meta !== null) {
            return $item->meta;
        }
        if (! $item->backup->onServer() && ($why = $this->backups->fetch($item->backup, $item->name)) !== null) {
            throw AgentException::rpcError('backup.fetch', -32000, $why);
        }
        $json = '';
        $offset = 0;
        do {
            $chunk = (array) $this->agent->call('backup.member.read', ['key' => $item->backup->key, 'name' => $item->name, 'member' => 'site.json', 'offset' => $offset], 60);
            $json .= base64_decode((string) ($chunk['data'] ?? ''));
            $offset = (int) ($chunk['offset'] ?? 0);
        } while ($offset < (int) ($chunk['size'] ?? 0) && ($chunk['data'] ?? '') !== '');
        $meta = json_decode($json, true);
        $item->update(['meta' => is_array($meta) ? $meta : ['version' => 1]]);

        return $item->meta;
    }

    /**
     * Undo: puts back the backup taken just before the restore, the same
     * parts, without another safety backup.
     */
    public function undo(BackupRestore $restore, ?User $user): BackupRestore|string
    {
        $safety = $restore->safetyBackup?->items()->first();
        if ($safety === null || $restore->status !== 'succeeded') {
            return 'Bu geri yükleme geri alınamaz.';
        }

        return $this->start($safety, $restore->parts, false, $user);
    }

    /**
     * Creates a deleted site again from the archive's description: its
     * account (with its customer, when they still exist), the site on the
     * server (without HTTPS until its certificates are back), and its
     * databases' and DNS zone's places in the panel.
     *
     * @param  array<string, mixed>  $meta
     */
    private function recreate(BackupItem $item, array $meta): Site
    {
        $s = $meta['site'];
        $accountName = (string) ($meta['account']['name'] ?? '');
        $customerId = $meta['account']['customer_id'] ?? $item->customer_id;
        $customer = $customerId ? Customer::find($customerId) : null;
        $versions = array_values((array) ($this->agent->call('php.versions') ?? []));
        $php = in_array($s['php_version'] ?? null, $versions, true) ? $s['php_version'] : (end($versions) ?: ($s['php_version'] ?? '8.4'));
        $stack = ($s['web_server'] ?? Site::NGINX) === Site::APACHE && app(Components::class)->isInstalled('apache') ? Site::APACHE : Site::NGINX;

        $site = DB::transaction(function () use ($s, $accountName, $customer, $php, $stack) {
            $account = SystemUser::firstOrCreate(['name' => $accountName]);
            if ($account->customer_id === null && $customer !== null) {
                $account->update(['customer_id' => $customer->id]);
            }

            return $account->sites()->create([
                'domain' => $s['domain'],
                'aliases' => array_values($s['aliases'] ?? []),
                'php_version' => $php,
                'web_server' => $stack,
                'web_root' => $s['web_root'] ?? null,
                'https_redirect' => (bool) ($s['https_redirect'] ?? true),
                'status' => SiteStatus::Provisioning,
            ]);
        });
        $this->provision($site->load('systemUser'));
        // Its DNS zone like any new site's, unless DNS comes from the backup.
        if (! in_array('dns', $item->restorable(), true)) {
            app(Zones::class)->forSite($site);
        }

        return $site;
    }

    /**
     * Sets the site up on the server (its account, directories and web
     * server configuration) without HTTPS: its certificates may not be on
     * this server until the restore puts them back.
     */
    private function provision(Site $site): void
    {
        $spec = $site->agentSpec();
        unset($spec['tls']);
        try {
            $result = $this->agent->call('site.apply', $spec, config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $site->update(['status' => SiteStatus::Failed, 'last_error' => $e->getMessage()]);
            throw $e;
        }
        $site->systemUser->update(['uid' => $result['uid'] ?? null, 'home' => $result['home'] ?? null]);
        $site->update(['status' => SiteStatus::Active, 'document_root' => $result['document_root'] ?? null, 'last_error' => null, 'provisioned_at' => $site->provisioned_at ?? now()]);
    }

    /**
     * Makes sure the panel lists the archive's databases and their users
     * (as they were, with their grants), and returns the users as the
     * agent restores them.
     *
     * @param  array<string, mixed>  $meta
     * @return list<array<string, mixed>>
     */
    private function prepareDatabases(Site $site, array $meta): array
    {
        $account = $site->systemUser;
        foreach ($meta['databases'] ?? [] as $d) {
            $database = Database::withTrashed()->where('server', $d['server'])->where('name', $d['name'])->first();
            if ($database === null) {
                Database::create(['server' => $d['server'], 'name' => $d['name'], 'system_user_id' => $account->id, 'site_id' => $site->id]);
            } elseif ($database->trashed()) {
                $database->restore();
                $database->update(['site_id' => $site->id, 'system_user_id' => $account->id]);
            }
        }
        $out = [];
        foreach ($meta['database_users'] ?? [] as $u) {
            $user = DatabaseUser::withTrashed()->where('server', $u['server'])->where('name', $u['name'])->first();
            if ($user === null) {
                $user = DatabaseUser::create(['server' => $u['server'], 'name' => $u['name'], 'system_user_id' => $account->id, 'hosts' => array_values($u['hosts'] ?? [])]);
            } elseif ($user->trashed()) {
                $user->restore();
            }
            foreach ($u['grants'] ?? [] as $g) {
                $database = Database::where('server', $u['server'])->where('name', $g['database'])->first();
                if ($database !== null && ! $user->databases()->whereKey($database->id)->exists()) {
                    $user->databases()->attach($database->id, ['access' => $g['access']]);
                }
            }
            $closed = (bool) $account->customer?->isSuspended();
            $out[] = [
                'server' => $user->server,
                'name' => $user->name,
                'hosts' => $closed ? [] : array_values($user->hosts ?? []),
                'grants' => $closed ? [] : $user->databases()->get()->map(fn (Database $d) => ['database' => $d->name, 'access' => $d->pivot->access])->values()->all(),
            ];
        }
        Quotas::forget();

        return $out;
    }

    /**
     * Mailboxes (with their passwords), forwarders and settings as they
     * were; mailboxes made after the backup stay. The stored mail itself
     * was put back by the agent.
     *
     * @param  array<string, mixed>  $meta
     */
    private function restoreMail(Site $site, array $meta): void
    {
        $m = $meta['mail'] ?? null;
        if ($m === null || ! app(MailSettings::class)->isSetUp()) {
            return;
        }
        $mail = app(MailDomains::class);
        $domain = MailDomain::where('name', $site->domain)->first();
        if ($domain === null) {
            $domain = MailDomain::create(['name' => $site->domain, 'site_id' => $site->id, 'status' => MailDomainStatus::Syncing]);
        } elseif ($domain->status === MailDomainStatus::Deleting) {
            $domain->update(['status' => MailDomainStatus::Syncing, 'disabled_at' => null]);
        }
        DB::transaction(function () use ($domain, $m, $site) {
            $domain->update(['site_id' => $site->id, 'spam_filter' => (bool) ($m['spam_filter'] ?? true), 'manage_dns' => (bool) ($m['manage_dns'] ?? true)]);
            foreach ($m['mailboxes'] ?? [] as $box) {
                $domain->mailboxes()->updateOrCreate(['name' => $box['name']], [
                    'password_hash' => $box['password_hash'],
                    'quota_mb' => $box['quota_mb'],
                    'forward_to' => $box['forward_to'] ?? null,
                    'keep_copy' => (bool) ($box['keep_copy'] ?? true),
                    'autoreply' => $box['autoreply'] ?? null,
                ]);
            }
            foreach ($m['forwarders'] ?? [] as $f) {
                $domain->forwarders()->updateOrCreate(['name' => $f['name']], ['destinations' => $f['destinations']]);
            }
        });
        $mail->sync($domain->fresh());
    }

    /**
     * The zone's records as they were (its settings too); a zone that was
     * deleted is created again.
     *
     * @param  array<string, mixed>  $meta
     */
    private function restoreDns(Site $site, array $meta): void
    {
        $dns = $meta['dns'] ?? null;
        if ($dns === null) {
            return;
        }
        $records = array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'type', 'content', 'priority', 'ttl'])), $dns['records'] ?? []);
        $zone = DnsZone::where('name', $dns['zone']['name'])->first();
        if ($zone !== null) {
            app(Zones::class)->replaceRecords($zone, $records);
            $zone->update(['site_id' => $zone->site_id ?? $site->id]);

            return;
        }
        $zone = DB::transaction(function () use ($dns, $site, $records) {
            $zone = DnsZone::create([
                ...array_intersect_key($dns['zone'], array_flip(['name', 'hostmaster', 'default_ttl', 'refresh', 'retry', 'expire', 'minimum'])),
                'site_id' => $site->id,
                'status' => ZoneStatus::Syncing,
            ]);
            $zone->records()->createMany($records);

            return $zone;
        });
        SyncDnsZone::dispatch($zone);
    }

    /**
     * Lists the certificates the agent put back (a deleted site's were
     * gone from the panel too) and serves HTTPS with the one in use.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, string>  $skipped
     */
    private function restoreCertificates(Site $site, array $meta, array $skipped): void
    {
        if (isset($skipped['certificates'])) {
            return;
        }
        $use = null;
        foreach ($meta['certificates'] ?? [] as $c) {
            if (isset($skipped['certificate:'.$c['name']])) {
                continue;
            }
            $cert = Certificate::firstOrNew(['name' => $c['name']]);
            if (! $cert->exists) {
                $cert->fill([
                    ...array_intersect_key($c, array_flip(['kind', 'source', 'domains', 'challenge', 'issuer', 'serial', 'fingerprint', 'auto_renew'])),
                    'site_id' => $site->id,
                    'not_before' => isset($c['not_before']) ? Carbon::parse($c['not_before']) : null,
                    'not_after' => isset($c['not_after']) ? Carbon::parse($c['not_after']) : null,
                    'status' => CertificateStatus::Active,
                    'issued_at' => isset($c['not_before']) ? Carbon::parse($c['not_before']) : now(),
                ])->save();
            }
            if ($c['in_use'] ?? false) {
                $use = $cert;
            }
        }
        if ($use !== null && $site->certificate_id === null) {
            $site->update(['certificate_id' => $use->id]);
        }
    }

    /**
     * Archives the agent fetched from targets for the restore stay on this
     * server for a while, then go.
     *
     * @param  list<BackupItem>  $chain
     */
    private function cacheFetched(array $chain): void
    {
        foreach ($chain as $i) {
            if (! $i->backup->local) {
                $i->backup->update(['cached_at' => now()]);
            }
        }
    }

    private function fail(BackupRestore $restore, string $why): void
    {
        $restore->update(['status' => 'failed', 'error' => $why, 'finished_at' => now()]);
    }
}
