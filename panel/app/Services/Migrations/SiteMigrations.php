<?php

namespace App\Services\Migrations;

use App\Jobs\PullSiteMigration;
use App\Jobs\RunSiteMigration;
use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\Site;
use App\Models\SiteMigration;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Backups\Backups;
use App\Services\Backups\Restores;
use App\Services\Customers\Quotas;
use App\Services\Databases\DatabaseServers;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;
use App\Services\Dns\ZoneTemplate;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;
use App\Services\Sites\SiteDomains;
use App\Support\DnsRecords;
use App\Support\Hostnames;
use Illuminate\Support\Str;
use Throwable;

/**
 * Moves an account from cPanel or a subscription from Plesk. A Plesk
 * subscription is first copied from the old server over SSH
 * (migrate.plesk.connect lists them, migrate.plesk.pull copies one into
 * an archive like cPanel's). The agent reads the account archive
 * (migrate.inspect) and turns it into Serfex site backups, one per site
 * (migrate.convert); each is then restored like a deleted site's backup,
 * which creates the site under the account, with its files, databases
 * (users keep their passwords, or get new ones written into the site's
 * settings), mailboxes, DNS zone (records of the old server pointed here)
 * and certificate. The converted backups are removed afterwards.
 */
class SiteMigrations
{
    /** What can be brought, in the order it is restored. */
    public const PARTS = ['files' => 'Dosyalar', 'databases' => 'Veritabanları', 'mail' => 'E-posta', 'dns' => 'DNS', 'certificates' => 'SSL sertifikaları'];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Restores $restores,
        private readonly Backups $backups,
    ) {}

    /**
     * Archives in the import folder that may be cPanel accounts.
     *
     * @return list<array{file: string, size: int, modified: string}>
     */
    public function files(): array
    {
        return array_values(array_map(fn ($f) => [
            'file' => (string) $f['file'],
            'size' => (int) ($f['size'] ?? 0),
            'modified' => (string) ($f['modified'] ?? ''),
        ], (array) $this->agent->call('migrate.scan', [], 60)));
    }

    /**
     * Reads an archive from the import folder ($file) or an upload
     * ($upload) and keeps what it holds. Returns the migration, or why not.
     */
    public function inspect(?string $file, ?string $upload, ?User $user): SiteMigration|string
    {
        try {
            $in = (array) $this->agent->call('migrate.inspect', array_filter(['file' => $file, 'upload' => $upload]), config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            return $this->reason($e);
        }
        $plan = (array) ($in['plan'] ?? []);
        $plan['free_backups'] = (int) ($in['free_backups'] ?? 0);
        $plan['free_home'] = (int) ($in['free_home'] ?? 0);

        return SiteMigration::create([
            'user_id' => $user?->id,
            'source' => (string) ($plan['source'] ?? 'cpanel'),
            'file' => $file,
            'upload' => $upload !== null ? (string) ($in['source'] ?? $upload) : null,
            'name' => $file ?? (string) ($plan['user'] ?? 'cpanel').'.tar.gz',
            'size' => (int) ($plan['size'] ?? 0),
            'plan' => $plan,
        ]);
    }

    /**
     * Signs in to a Plesk server and lists its subscriptions. $conn holds
     * host, port, user, password (empty: Serfex's key) and host_key (empty
     * the first time). Returns the server, or why not.
     *
     * @param  array{host: string, port: int, user: string, password?: string, host_key?: string}  $conn
     * @return array<string, mixed>|string
     */
    public function pleskConnect(array $conn): array|string
    {
        try {
            $server = (array) $this->agent->call('migrate.plesk.connect', array_filter($conn, fn ($v) => $v !== '' && $v !== null), 150);
        } catch (AgentException $e) {
            return $this->reason($e);
        }

        return [
            'host_key' => (string) ($server['host_key'] ?? ''),
            'fingerprint' => (string) ($server['fingerprint'] ?? ''),
            'hostname' => (string) ($server['hostname'] ?? ''),
            'version' => (string) ($server['version'] ?? ''),
            'subscriptions' => array_values(array_map(fn ($s) => [
                'id' => (int) $s['id'],
                'domain' => (string) $s['domain'],
                'user' => (string) ($s['user'] ?? ''),
                'owner' => (string) ($s['owner'] ?? ''),
                'size' => (int) ($s['size'] ?? 0),
                'domains' => (int) ($s['domains'] ?? 0),
            ], (array) ($server['subscriptions'] ?? []))),
        ];
    }

    /**
     * Starts copying a subscription from the Plesk server (PullSiteMigration
     * runs it). The password is kept, encrypted, only until the copy is
     * done. Returns the migration, or why not.
     *
     * @param  array{host: string, port: int, user: string, password?: string, host_key: string}  $conn
     * @param  array{id: int, domain: string, user: string, size: int}  $subscription
     */
    public function pleskPull(array $conn, array $subscription, ?User $user): SiteMigration|string
    {
        if ($this->busy()) {
            return __(Backups::BUSY);
        }
        $migration = SiteMigration::create([
            'user_id' => $user?->id,
            'source' => 'plesk',
            'upload' => 'pl-'.Str::lower(Str::random(20)),
            'name' => $subscription['domain'].' · '.$conn['host'],
            'size' => (int) $subscription['size'],
            'status' => 'pulling',
            'plan' => ['source' => 'plesk', 'user' => $subscription['user'], 'main_domain' => $subscription['domain']],
            'options' => ['plesk' => [
                'host' => $conn['host'], 'port' => (int) $conn['port'], 'user' => $conn['user'], 'host_key' => $conn['host_key'],
                'subscription' => (int) $subscription['id'], 'run' => 'pull-'.Str::lower(Str::random(12)), 'key' => ($conn['password'] ?? '') === '',
            ]],
            'secrets' => ($conn['password'] ?? '') !== '' ? ['ssh_password' => $conn['password']] : null,
            'started_at' => now(),
        ]);
        PullSiteMigration::dispatch($migration);

        return $migration;
    }

    /**
     * Copies the subscription and reads the copy (PullSiteMigration calls
     * it): the migration is then ready for the administrator's choices.
     */
    public function pull(SiteMigration $migration): void
    {
        $plesk = (array) ($migration->options['plesk'] ?? []);
        $password = (string) ($migration->secrets['ssh_password'] ?? '');
        try {
            $this->agent->call('migrate.plesk.pull', array_filter([
                'host' => $plesk['host'], 'port' => $plesk['port'], 'user' => $plesk['user'], 'password' => $password,
                'host_key' => $plesk['host_key'], 'subscription' => $plesk['subscription'],
                'source' => $migration->upload, 'run' => $plesk['run'],
            ], fn ($v) => $v !== ''), config('serfex.agent.server_backup_timeout'));
            $in = (array) $this->agent->call('migrate.inspect', ['source' => $migration->upload], config('serfex.agent.backup_timeout'));
        } catch (AgentException $e) {
            $migration->update(['secrets' => null]);
            $this->fail($migration, $this->reason($e));
            $this->discardArchive($migration);

            return;
        }
        $plan = (array) ($in['plan'] ?? []);
        $plan['free_backups'] = (int) ($in['free_backups'] ?? 0);
        $plan['free_home'] = (int) ($in['free_home'] ?? 0);
        $migration->update([
            'status' => SiteMigration::READY,
            'plan' => $plan,
            'size' => (int) ($plan['size'] ?? 0),
            'secrets' => null,
            'started_at' => null,
        ]);
    }

    /**
     * The key Serfex signs in to the Plesk server with, for its
     * authorized_keys (the one SFTP backup targets use).
     */
    public function publicKey(): ?string
    {
        try {
            return (string) ($this->agent->call('backup.status')['public_key'] ?? '') ?: null;
        } catch (AgentException) {
            return null;
        }
    }

    /**
     * What stands in the way and what will not come, worked out against
     * this server: domains and databases that exist here, software not
     * installed, disk space.
     *
     * @return array<string, mixed>
     */
    public function review(SiteMigration $migration): array
    {
        $plan = $migration->plan;
        $domains = app(SiteDomains::class);
        $sites = [];
        foreach ($plan['sites'] ?? [] as $s) {
            $sites[$s['domain']] = $domains->taken($s['domain']);
        }
        $account = SystemUser::with('customer')->where('name', $plan['user'] ?? '')->first();
        $existingDbs = Database::where('server', 'mysql')->whereIn('name', array_column($plan['databases'] ?? [], 'name'))->pluck('name')->all();
        $existingUsers = DatabaseUser::where('server', 'mysql')->whereIn('name', array_column($plan['database_users'] ?? [], 'name'))->pluck('name')->all();
        $need = (int) ($plan['unpacked'] ?? 0);

        return [
            'taken' => $sites,
            'account' => $account,
            'account_valid' => preg_match(Hostnames::SYSTEM_USER_PATTERN, (string) ($plan['user'] ?? '')) === 1 && ! in_array($plan['user'], Hostnames::RESERVED_USERS, true),
            'existing_databases' => $existingDbs,
            'existing_users' => $existingUsers,
            'mysql' => app(DatabaseServers::class)->isInstalled('mysql'),
            'mail' => app(MailSettings::class)->isSetUp(),
            'space_ok' => ($plan['free_backups'] ?? 0) >= 2 * ($plan['size'] ?? 0) + (1 << 30) && ($plan['free_home'] ?? 0) >= $need,
            'space_needed' => 2 * ($plan['size'] ?? 0) + (1 << 30),
        ];
    }

    /**
     * Starts the move with the administrator's choices. Returns why not.
     *
     * @param  array{sites: list<string>, parts: list<string>, customer_id: ?int}  $options
     */
    public function start(SiteMigration $migration, array $options, ?User $user): ?string
    {
        if ($migration->status !== SiteMigration::READY) {
            return __('Bu taşıma zaten başladı.');
        }
        if ($this->busy()) {
            return __(Backups::BUSY);
        }
        $review = $this->review($migration);
        if (! $review['account_valid']) {
            return $migration->source === 'plesk'
                ? __('Plesk sistem kullanıcısı :user burada sistem kullanıcısı olamaz.', ['user' => $migration->plan['user'] ?? ''])
                : __('cPanel kullanıcı adı :user burada sistem kullanıcısı olamaz.', ['user' => $migration->plan['user'] ?? '']);
        }
        $sites = array_values(array_filter($options['sites'], fn ($d) => array_key_exists($d, $review['taken']) && $review['taken'][$d] === null));
        if ($sites === []) {
            return __('Taşınacak en az bir site seçin.');
        }
        $customer = isset($options['customer_id']) ? Customer::find($options['customer_id']) : null;
        $account = $review['account'];
        if ($account !== null && $account->customer_id !== null && $account->customer_id !== $customer?->id) {
            return __('Bu sistem kullanıcısı başka bir müşteriye ait.');
        }
        $parts = array_values(array_intersect(array_keys(self::PARTS), $options['parts']));
        if (! $review['mysql']) {
            $parts = array_values(array_diff($parts, ['databases']));
        }
        if (! $review['mail']) {
            $parts = array_values(array_diff($parts, ['mail']));
        }
        $migration->update([
            'status' => 'queued',
            'options' => [...($migration->options ?? []), 'sites' => $sites, 'parts' => $parts],
            'customer_id' => $customer?->id,
            'user_id' => $user?->id ?? $migration->user_id,
            'run' => 'migrate-'.Str::lower(Str::random(12)),
            'error' => null,
        ]);
        RunSiteMigration::dispatch($migration);

        return null;
    }

    /**
     * Whether a move, a backup or a restore runs now (Backups counts moves).
     */
    public function busy(): bool
    {
        return $this->backups->busy();
    }

    /**
     * Runs the move (RunSiteMigration calls it).
     */
    public function run(SiteMigration $migration): void
    {
        $migration->update(['status' => 'converting', 'started_at' => now()]);
        $plan = $migration->plan;
        $account = (string) $plan['user'];
        $options = $migration->options;
        $dns = app(DnsSettings::class);
        app(ZoneTemplate::class)->detectAddresses();
        $key = now()->format('Ymd-His').'-mig-'.Str::lower(Str::random(6));
        try {
            $converted = (array) $this->agent->call('migrate.convert', [
                ...$migration->agentSource(),
                'key' => $key,
                'run' => $migration->run,
                'account' => $account,
                'sites' => $options['sites'],
                'parts' => $options['parts'],
                'ipv4' => $dns->ipv4(),
                'ipv6' => $dns->ipv6(),
            ], config('serfex.agent.server_backup_timeout'));
        } catch (AgentException $e) {
            $this->fail($migration, $this->reason($e));

            return;
        }
        $migration->update(['status' => 'restoring', 'backup_key' => $key]);

        // The account, with its customer.
        $systemUser = SystemUser::firstOrCreate(['name' => $account]);
        if ($migration->customer_id !== null && $systemUser->customer_id === null) {
            $systemUser->update(['customer_id' => $migration->customer_id]);
        }

        $backup = Backup::create([
            'key' => $key,
            'scope' => 'site',
            'type' => 'full',
            'trigger' => 'migration',
            'local' => true,
            'status' => 'succeeded',
            'size' => (int) ($converted['size'] ?? 0),
            'run' => $migration->run,
            'source_host' => $migration->source.':'.$account,
            'user_id' => $migration->user_id,
            'started_at' => $migration->started_at,
            'finished_at' => now(),
        ]);
        $secrets = (array) ($migration->secrets ?? []);
        $secrets['databases'] = array_values(array_map(fn ($p) => [
            'name' => (string) $p['name'], 'password' => (string) $p['password'], 'files' => array_values((array) ($p['files'] ?? [])),
        ], (array) ($converted['passwords'] ?? [])));
        $secrets['mailboxes'] = [];
        $results = [];
        foreach ((array) ($converted['items'] ?? []) as $i) {
            $item = $backup->items()->create([
                'name' => $i['name'],
                'size' => (int) ($i['size'] ?? 0),
                'parts' => (array) ($i['parts'] ?? []),
                'status' => 'succeeded',
            ]);
            try {
                $results[] = $this->restoreSite($migration, $item, $secrets);
            } catch (Throwable $e) {
                report($e);
                $results[] = ['domain' => $item->name, 'status' => 'failed', 'restored' => [], 'skipped' => [], 'notes' => [], 'error' => $e->getMessage()];
            }
            $migration->update(['result' => ['sites' => $results], 'secrets' => $secrets]);
        }
        // The converted archives are not kept: the original archive is
        // the copy of what came.
        $this->backups->purge($backup);
        $failed = collect($results)->where('status', 'failed')->count();
        if ($failed === 0 && $migration->upload !== null) {
            $this->discardArchive($migration);
        }
        Quotas::forget();
        $migration->update([
            'status' => $failed === 0 ? 'succeeded' : ($failed < count($results) ? 'partial' : 'failed'),
            'result' => ['sites' => $results, 'notes' => (array) ($converted['notes'] ?? [])],
            'secrets' => $secrets,
            'finished_at' => now(),
        ]);
    }

    /**
     * Restores one converted site: its description made fit for this
     * server first.
     *
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed>
     */
    private function restoreSite(SiteMigration $migration, BackupItem $item, array &$secrets): array
    {
        $out = ['domain' => $item->name, 'status' => 'failed', 'restored' => [], 'skipped' => [], 'notes' => []];
        try {
            $meta = $this->restores->meta($item->load('backup'));
        } catch (AgentException $e) {
            return [...$out, 'error' => $e->reason ?: $e->getMessage()];
        }
        if ($meta === null || empty($meta['site'])) {
            return [...$out, 'error' => __('Yedek okunamadı.')];
        }
        $parts = $migration->options['parts'];
        $meta['account']['customer_id'] = $migration->customer_id;

        // Databases and users that exist here are not replaced.
        $existing = Database::withTrashed()->where('server', 'mysql')->whereIn('name', array_column($meta['databases'] ?? [], 'name'))->pluck('name')->all();
        foreach ($existing as $name) {
            $out['notes'][] = ['code' => 'database-exists', 'subject' => $name];
        }
        $meta['databases'] = array_values(array_filter($meta['databases'] ?? [], fn ($d) => ! in_array($d['name'], $existing, true)));
        $users = DatabaseUser::withTrashed()->where('server', 'mysql')->whereIn('name', array_column($meta['database_users'] ?? [], 'name'))->pluck('name')->all();
        foreach ($users as $name) {
            $out['notes'][] = ['code' => 'database-user-exists', 'subject' => $name];
        }
        $meta['database_users'] = array_values(array_filter($meta['database_users'] ?? [], fn ($u) => ! in_array($u['name'], $users, true)));

        // Mailboxes whose old password hash cannot be used get a new password.
        if (isset($meta['mail']['mailboxes'])) {
            foreach ($meta['mail']['mailboxes'] as $n => $box) {
                if (($box['password_hash'] ?? '') === '') {
                    $password = Str::password(16, symbols: false);
                    $meta['mail']['mailboxes'][$n]['password_hash'] = MailPassword::hash($password);
                    $secrets['mailboxes'][] = ['name' => $box['name'].'@'.$item->name, 'password' => $password];
                }
            }
        }

        // The zone: Serfex's starting records (its name servers, this
        // server's addresses), then the old records that do not clash.
        if (in_array('dns', $parts, true) && ($meta['dns'] ?? null) !== null) {
            $meta['dns'] = [
                'zone' => $this->zoneSettings($item->name),
                'records' => $this->mergeRecords($item->name, (array) $meta['dns']['records']),
            ];
        } else {
            $meta['dns'] = null;
        }
        if (Certificate::where('name', $item->name)->exists()) {
            $parts = array_values(array_diff($parts, ['certificates']));
        }
        $item->update(['meta' => $meta]);

        $restore = BackupRestore::create([
            'backup_item_id' => $item->id,
            'domain' => $item->name,
            'parts' => array_values(array_intersect($parts, $item->restorable())),
            'status' => 'queued',
            'run' => 'restore-'.Str::lower(Str::random(12)),
            'user_id' => $migration->user_id,
            'result' => ['safety' => false, 'provision' => false],
        ]);
        $this->restores->run($restore);
        $restore->refresh();
        $site = $restore->site_id !== null ? Site::find($restore->site_id) : null;
        if ($site !== null && in_array('dns', $parts, true)) {
            foreach ((array) ($meta['migration']['zones'] ?? []) as $z) {
                $this->aliasZone($site, (string) $z['name'], (array) ($z['records'] ?? []));
            }
        }
        $result = (array) ($restore->result ?? []);

        return [
            ...$out,
            'status' => $restore->status === 'succeeded' ? 'succeeded' : 'failed',
            'site_id' => $site?->id,
            'restore_id' => $restore->id,
            'restored' => array_values((array) ($result['restored'] ?? [])),
            // Only what the administrator chose: the files part also asks
            // for a home folder and a Laravel database, which most sites lack.
            'skipped' => array_intersect_key((array) ($result['skipped'] ?? []), self::PARTS),
            'error' => $restore->error,
        ];
    }

    /**
     * A zone of an alias (a cPanel parked domain), unless it is here.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function aliasZone(Site $site, string $name, array $records): void
    {
        if (DnsZone::where('name', $name)->exists() || ! in_array($name, $site->aliases ?? [], true)) {
            return;
        }
        $zones = app(Zones::class);
        $zone = $zones->create($name, $site);
        $zones->replaceRecords($zone, $this->mergeRecords($name, $records));
    }

    /**
     * @return array<string, mixed>
     */
    private function zoneSettings(string $name): array
    {
        $dns = app(DnsSettings::class);

        return ['name' => $name, 'hostmaster' => $dns->hostmaster(), 'default_ttl' => $dns->defaultTtl(), ...$dns->soa(), 'dnssec' => false, 'dnssec_key' => null];
    }

    /**
     * Serfex's starting records for the zone, then the old ones in the
     * panel's form that do not clash with them (a CNAME shares its name
     * with nothing).
     *
     * @param  list<array<string, mixed>>  $old
     * @return list<array{name: string, type: string, content: string, priority: ?int, ttl: int}>
     */
    public function mergeRecords(string $zone, array $old): array
    {
        $base = app(ZoneTemplate::class)->records($zone);
        $out = $base;
        foreach ($old as $r) {
            $type = strtoupper((string) ($r['type'] ?? ''));
            $name = DnsRecords::normalizeName((string) ($r['name'] ?? ''), $zone);
            if (! in_array($type, DnsRecords::TYPES, true) || $name === null || ($name === '@' && $type === 'NS')) {
                continue;
            }
            [$content, $error] = DnsRecords::normalizeContent($type, (string) ($r['content'] ?? ''), $zone);
            if ($error !== null) {
                continue;
            }
            $clash = collect($base)->contains(fn ($e) => $e['name'] === $name && ($e['type'] === $type || $e['type'] === 'CNAME' || $type === 'CNAME'));
            if ($clash) {
                continue;
            }
            $priority = in_array($type, DnsRecords::WITH_PRIORITY, true) && DnsRecords::isValidPriority($r['priority'] ?? null) ? (int) $r['priority'] : null;
            $ttl = min(max((int) ($r['ttl'] ?? 3600), DnsRecords::MIN_TTL), DnsRecords::MAX_TTL);
            $out[] = ['name' => $name, 'type' => $type, 'content' => $content, 'priority' => $priority, 'ttl' => $ttl];
        }

        return $out;
    }

    /**
     * A note from the agent or the restore, in words.
     *
     * @param  array{code: string, subject?: string}  $note
     */
    public static function noteText(array $note): string
    {
        $s = ['subject' => (string) ($note['subject'] ?? '')];

        return match ($note['code']) {
            'bad-user' => __(':subject kullanıcı adı burada sistem kullanıcısı olamaz.', $s),
            'bad-domain' => __(':subject geçerli bir alan adı değil; taşınmaz.', $s),
            'no-docroot' => __(':subject alan adının klasörü ev klasörünün dışında; taşınmaz.', $s),
            'subdomain-without-site' => __(':subject alt alan adının bağlı olduğu site arşivde yok; taşınmaz.', $s),
            'subdomain-docroot' => __(':subject alt alan adının klasörü sitenin klasörünü içeriyor; alt alan adı taşınmaz, dosyaları sitede kalır.', $s),
            'bad-database' => __(':subject veritabanının adı burada kullanılamıyor; taşınmaz.', $s),
            'postgresql' => __(':subject PostgreSQL veritabanı bu sürümde taşınmaz; pg_dump ile ayrıca aktarın.', $s),
            'bad-database-user' => __(':subject veritabanı kullanıcısının adı burada kullanılamıyor; taşınmaz.', $s),
            'bad-mailbox' => __(':subject posta kutusunun adı burada kullanılamıyor; taşınmaz.', $s),
            'mail-without-site' => __(':subject alan adının postası taşınmaz: Serfex postayı yalnızca sitelerin kendi alan adında tutar.', $s),
            'database-exists' => __(':subject veritabanı bu sunucuda zaten var; üzerine yazılmadı.', $s),
            'database-user-exists' => __(':subject veritabanı kullanıcısı bu sunucuda zaten var; değiştirilmedi.', $s),
            'plesk-no-hosting' => __(':subject alan adının Plesk\'te web barındırması yok (yönlendirme ya da yalnızca ad); taşınmaz.', $s),
            'alias-mail' => __(':subject takma adının postası taşınmaz: Serfex postayı yalnızca sitelerin kendi alan adında tutar.', $s),
            'remote-database' => __(':subject veritabanı başka bir veritabanı sunucusunda; taşınmaz.', $s),
            'renamed-user' => __('Plesk\'teki :subject sistem kullanıcısının adı burada kullanılamıyor; hesap düzeltilmiş adla açılır.', $s),
            'old-path' => __(':subject dosyasında eski sunucunun /var/www/vhosts/ yolu geçiyor; taşımadan sonra yeni yola göre düzeltin.', $s),
            default => $note['code'].($s['subject'] !== '' ? ': '.$s['subject'] : ''),
        };
    }

    /**
     * Forgets a migration that was read but not started, with its upload.
     */
    public function discard(SiteMigration $migration): void
    {
        if ($migration->isRunning()) {
            return;
        }
        if ($migration->upload !== null) {
            $this->discardArchive($migration);
        }
        $migration->delete();
    }

    private function discardArchive(SiteMigration $migration): void
    {
        try {
            $this->agent->call('migrate.discard', ['source' => $migration->upload]);
            $migration->update(['upload' => null]);
        } catch (AgentException) {
            // An upload left behind is removed with the next one.
        }
    }

    /**
     * The progress log: the conversion's, then each site's restore.
     *
     * @return list<string>
     */
    public function runs(SiteMigration $migration): array
    {
        $runs = isset($migration->options['plesk']['run']) ? [$migration->options['plesk']['run']] : [];
        if ($migration->run !== null) {
            $runs[] = $migration->run;
        }
        foreach ((array) ($migration->result['sites'] ?? []) as $s) {
            if (isset($s['restore_id']) && ($r = BackupRestore::find($s['restore_id'])) !== null) {
                $runs[] = $r->run;
            }
        }

        return $runs;
    }

    public function failRunning(SiteMigration $migration, string $why): void
    {
        if ($migration->isRunning()) {
            $this->fail($migration, $why);
        }
    }

    private function fail(SiteMigration $migration, string $why): void
    {
        $migration->update(['status' => 'failed', 'error' => $why, 'finished_at' => now()]);
    }

    private function reason(AgentException $e): string
    {
        $why = $e->reason ?: $e->getMessage();

        return match (true) {
            $e instanceof AgentUnavailableException => __('Sunucu ajanına ulaşılamıyor.'),
            str_contains($why, 'not a cPanel account') => __('Bu dosya bir cPanel hesap yedeği değil. WHM\'deki aktarım aracının ya da cPanel\'deki tam yedeğin oluşturduğu .tar.gz dosyasını kullanın.'),
            str_contains($why, 'upload it again') => __('Arşiv artık sunucuda değil; yeniden yükleyin.'),
            str_contains($why, 'not enough free disk space to copy') => __('Aboneliği bu sunucuya almak için diskte yeterli boş yer yok.'),
            str_contains($why, 'not enough free disk space') => __('Arşivi dönüştürmek için diskte yeterli boş yer yok.'),
            str_contains($why, 'could not reach') => __('Sunucuya SSH ile ulaşılamadı. Adresi, bağlantı noktasını ve güvenlik duvarını denetleyin.'),
            str_contains($why, 'refused the user name or password') => __('Sunucu kullanıcı adını ya da parolayı kabul etmedi.'),
            str_contains($why, 'refused Serfex\'s key') => __('Sunucu Serfex\'in anahtarını kabul etmedi. Genel anahtarı eski sunucuda bağlandığınız kullanıcının ~/.ssh/authorized_keys dosyasına ekleyin.'),
            str_contains($why, 'host key changed') => __('Sunucunun SSH anahtarı ilk bağlantıdakinden farklı. Sunucuyu yeniden kurduysanız baştan bağlanın; kurmadıysanız araya başka biri girmiş olabilir, devam etmeyin.'),
            str_contains($why, 'not a Plesk server') => __('Bu sunucuda Plesk bulunamadı (/usr/local/psa yok).'),
            str_contains($why, 'cannot use sudo') => __('Bu kullanıcı root değil ve parolasız sudo kullanamıyor. root olarak bağlanın.'),
            $e->getCode() === -32006 => __(Backups::BUSY),
            default => $why,
        };
    }
}
