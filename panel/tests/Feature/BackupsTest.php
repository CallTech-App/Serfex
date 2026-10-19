<?php

namespace Tests\Feature;

use App\Jobs\PurgeBackup;
use App\Jobs\RestoreBackup;
use App\Jobs\RunBackup;
use App\Jobs\SyncDnsZone;
use App\Livewire\Backups\BackupIndex;
use App\Models\Backup;
use App\Models\BackupItem;
use App\Models\BackupRestore;
use App\Models\BackupTarget;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Backups\BackupSettings;
use App\Services\Backups\Restores;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Mail\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Yedekler: whole-server and site backups (with the panel's own database),
 * targets tested before they are saved, the schedule with incremental
 * chains and retention, restores (into a live site with a safety backup,
 * or recreating a deleted one), delete with Undo, imports, downloads, and
 * customers' own backups within their package.
 */
class BackupsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Process::fake(['*pg_dump*' => Process::result('-- dump')]);
        $this->agent = new FakeAgent([
            'backup.create' => fn ($p) => [
                'key' => $p['key'],
                'items' => array_map(fn ($i) => ['name' => $i['name'], 'size' => 1000, 'parts' => $i['name'] === 'panel' ? ['database' => 10, 'settings' => 5] : ['files' => 600, 'databases' => 300, 'mail' => 100],
                    'skipped' => [], 'incremental' => ($p['incremental'] && $i['name'] !== 'panel') ? ['files', 'mail'] : [], 'warnings' => []], $p['items']),
                'size' => 1000 * count($p['items']),
                'local' => ! isset($p['target']) || $p['keep_local'],
                'remote' => isset($p['target']),
                'encrypted' => isset($p['passphrase']),
                'seconds' => 12,
            ],
            'backup.restore' => ['restored' => ['files', 'databases'], 'skipped' => []],
            'backup.delete' => ['deleted' => true],
            'backup.upload.write' => fn ($p) => ['size' => $p['offset'] + strlen(base64_decode($p['data']))],
            'backup.target.test' => ['ok' => true],
            'backup.status' => ['path' => '/var/lib/servik/backups', 'used' => 4096, 'total' => 1 << 30, 'free' => 1 << 29, 'public_key' => 'ssh-ed25519 AAAAC3Nza servik-backup', 'busy' => false],
            'backup.run.read' => ['data' => "▸ Yedek başladı\n✓ Yedek tamamlandı\n", 'offset' => 40, 'size' => 40],
            'site.apply' => ['uid' => 1001, 'home' => '/home/kahve', 'document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html'],
            'php.versions' => ['8.3', '8.4'],
            'mail.domain.apply' => ['dkim' => ['name' => 'servik._domainkey', 'record' => 'v=DKIM1; k=rsa; p=abc']],
            'dns.zone.apply' => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'provisioned_at' => now()]);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    /**
     * The site with a database and its user, a mailbox and a DNS zone.
     */
    private function fullSite(): void
    {
        $db = Database::factory()->forSite($this->site, 'shop')->create();
        $user = DatabaseUser::create(['server' => 'mysql', 'name' => 'kahve_app', 'system_user_id' => $this->site->system_user_id, 'hosts' => ['10.0.0.5']]);
        $user->databases()->attach($db->id, ['access' => 'all']);
        $mail = MailDomain::factory()->create(['name' => $this->site->domain, 'site_id' => $this->site->id]);
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'name' => 'info']);
        $zone = DnsZone::factory()->create(['name' => $this->site->domain, 'site_id' => $this->site->id]);
        $zone->records()->create(['name' => '@', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600]);
        $zone->records()->create(['name' => 'www', 'type' => 'CNAME', 'content' => 'kahveatolyesi.com.tr.', 'ttl' => 3600]);
    }

    private function serverBackup(string $trigger = 'manual', ?Carbon $at = null): Backup
    {
        if ($at !== null) {
            Carbon::setTestNow($at);
        }
        $backup = app(Backups::class)->startServer(null, $trigger);
        $this->assertInstanceOf(Backup::class, $backup);
        app(Backups::class)->take($backup->fresh());

        return $backup->fresh('items');
    }

    public function test_the_page_shows_backups_targets_and_the_schedule(): void
    {
        $this->serverBackup();

        $this->get('/yedekler')->assertOk()->assertSee('Tüm sunucu')->assertSee('Şimdi yedekle')->assertSee('1 site + panel');
        $this->get('/yedekler?sekme=hedefler')->assertOk()->assertSee('Yerel disk')->assertSee('Hedef ekle');
        $this->get('/yedekler?sekme=zamanlama')->assertOk()->assertSee('Her gün')->assertSee('Saklama');
        $this->get('/')->assertOk()->assertSee('Son sunucu yedeği');
    }

    public function test_a_server_backup_takes_every_site_and_the_panel(): void
    {
        $this->fullSite();
        $other = Site::factory()->create(['domain' => 'blog.example.com', 'provisioned_at' => now()]);
        Site::factory()->create(['domain' => 'yeni.example.com']);

        Livewire::test(BackupIndex::class)
            ->call('openBackup')
            ->assertSet('backupScope', 'server')
            ->call('startBackup')
            ->assertHasNoErrors()
            ->assertSee('Tüm sunucu yedekleniyor');
        Queue::assertPushed(RunBackup::class, fn ($job) => $job->connection === 'backups' && $job->queue === 'backups');
        $backup = Backup::firstOrFail();
        app(Backups::class)->take($backup);

        $call = $this->agent->called('backup.create')[0];
        $this->assertSame('server', $call['scope']);
        $this->assertFalse($call['chain'], 'manual backups do not move the incremental chain');
        $this->assertSame(['blog.example.com', 'kahveatolyesi.com.tr', 'panel'], array_column($call['items'], 'name'));
        $kahve = collect($call['items'])->firstWhere('name', 'kahveatolyesi.com.tr');
        $this->assertSame(['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve'], $kahve['site']);
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop']], $kahve['databases']);
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_app']], $kahve['database_users']);
        $this->assertTrue($kahve['mail']);
        $this->assertTrue($kahve['home']);
        $this->assertSame('info', $kahve['meta']['mail']['mailboxes'][0]['name']);
        $this->assertSame([['database' => 'kahve_shop', 'access' => 'all']], $kahve['meta']['database_users'][0]['grants']);
        $this->assertCount(2, $kahve['meta']['dns']['records']);
        // The panel's database and .env were uploaded for its archive.
        $panel = collect($call['items'])->firstWhere('name', 'panel');
        $this->assertArrayHasKey('panel.sql.gz', (array) $panel['uploads']);
        $this->assertNotEmpty($this->agent->called('backup.upload.write'));
        Process::assertRan(fn ($p) => str_contains(implode(' ', (array) $p->command), 'pg_dump'));

        $backup->refresh();
        $this->assertSame('succeeded', $backup->status);
        $this->assertSame(3000, $backup->size);
        $this->assertSame(3, $backup->items()->where('status', 'succeeded')->count());
        $this->assertSame($other->id, $backup->items()->where('name', 'blog.example.com')->value('site_id'));
    }

    public function test_a_failed_archive_makes_the_backup_partial_and_shows_on_the_dashboard(): void
    {
        $this->agent->answers['backup.create'] = fn ($p) => ['items' => [
            ['name' => 'kahveatolyesi.com.tr', 'error' => 'database kahve_shop: mysqldump failed'],
            ['name' => 'panel', 'size' => 10, 'parts' => ['database' => 10]],
        ], 'size' => 10, 'local' => true];

        $backup = $this->serverBackup();

        $this->assertSame('partial', $backup->status);
        $problems = app(Backups::class)->problems();
        $this->assertStringContainsString('1 arşiv alınamadı: kahveatolyesi.com.tr', $problems[0]['text']);
        $this->get('/')->assertSee('1 arşiv alınamadı');
        $this->get('/yedekler')->assertSee('Kısmen alındı');
    }

    public function test_a_failed_agent_call_fails_the_backup(): void
    {
        $this->agent->answers['backup.create'] = AgentException::rpcError('backup.create', -32000, 'no space left on device');

        $backup = $this->serverBackup();

        $this->assertSame('failed', $backup->status);
        $this->assertSame('no space left on device', $backup->error);
        $this->assertSame('crit', app(Backups::class)->problems()[0]['level']);
    }

    public function test_one_backup_runs_at_a_time(): void
    {
        app(Backups::class)->startServer(null);

        $this->assertSame(Backups::BUSY, app(Backups::class)->startServer(null));
        Livewire::test(BackupIndex::class)->call('openBackup')->set('backupScope', 'site')->set('backupSite', $this->site->id)
            ->call('startBackup')->assertHasErrors('backupSite');
    }

    public function test_a_target_is_tested_before_it_is_saved(): void
    {
        $this->agent->answers['backup.target.test'] = AgentException::rpcError('backup.target.test', -32000, 'writing a test file failed: S3 PUT: 403 AccessDenied');

        Livewire::test(BackupIndex::class)
            ->call('openTarget')
            ->set('tName', 'Wasabi')
            ->set('tProvider', 'wasabi')
            ->assertSet('tEndpoint', 's3.eu-central-2.wasabisys.com')
            ->set('tBucket', 'servik-yedek')
            ->set('tAccessKey', 'AKIA1')
            ->set('tSecretKey', 'gizli')
            ->call('saveTarget')
            ->assertHasErrors('tName')
            ->assertSee('403 AccessDenied');
        $this->assertSame(0, BackupTarget::withTrashed()->count());

        $this->agent->answers['backup.target.test'] = ['ok' => true];
        Livewire::test(BackupIndex::class)
            ->call('openTarget')->set('tName', 'Wasabi')->set('tProvider', 'wasabi')->set('tBucket', 'servik-yedek')
            ->set('tAccessKey', 'AKIA1')->set('tSecretKey', 'gizli')
            ->call('saveTarget')->assertHasNoErrors();
        $target = BackupTarget::firstOrFail();
        $this->assertSame('ok', $target->status);
        $this->assertSame('gizli', $target->config['secret_key']);
        $this->assertStringNotContainsString('gizli', (string) $target->getRawOriginal('config'), 'keys are stored encrypted');
        $spec = $this->agent->called('backup.target.test')[1];
        $this->assertSame(['s3', 'servik-yedek', 'servik/'.BackupTarget::serverName()], [$spec['type'], $spec['bucket'], $spec['prefix']]);
    }

    public function test_an_sftp_target_learns_the_servers_host_key_and_shows_the_agents_key(): void
    {
        $this->agent->answers['backup.target.test'] = ['ok' => true, 'host_key' => 'ssh-ed25519 AAAAhost', 'fingerprint' => 'SHA256:abc'];

        Livewire::test(BackupIndex::class)
            ->call('openTarget')->set('tType', 'sftp')
            ->assertSee('ssh-ed25519 AAAAC3Nza servik-backup')
            ->set('tName', 'Yedek sunucusu')->set('tHost', 'yedek.example.com')->set('tUser', 'servik')
            ->call('saveTarget')->assertHasNoErrors();

        $target = BackupTarget::firstOrFail();
        $this->assertSame('ssh-ed25519 AAAAhost', $target->config['host_key']);
        $this->assertSame('ssh-ed25519 AAAAhost', $target->agentSpec()['host_key']);
    }

    public function test_remote_copies_are_encrypted_and_the_local_copy_goes(): void
    {
        $target = BackupTarget::create(['name' => 'MinIO', 'type' => 's3', 'config' => ['endpoint' => 'http://127.0.0.1:9000', 'bucket' => 'yedek', 'access_key' => 'a', 'secret_key' => 'b', 'path_style' => true, 'folder' => 'servik'], 'status' => 'ok']);
        app(BackupSettings::class)->save(['target_id' => $target->id]);

        $backup = $this->serverBackup();

        $call = $this->agent->called('backup.create')[0];
        $this->assertSame('yedek', $call['target']['bucket']);
        $this->assertFalse($call['keep_local']);
        $this->assertSame(app(BackupSettings::class)->passphrase(), $call['passphrase']);
        $this->assertTrue($backup->remote && $backup->encrypted && ! $backup->local);
        $this->assertSame('MinIO', $backup->destinationLabel());
        $this->assertSame(app(BackupSettings::class)->passphrase(), $backup->passphrase);
    }

    public function test_the_schedule_takes_a_full_backup_then_incrementals_and_restores_the_chain(): void
    {
        // Sunday 03:00 full, then incrementals on the days after.
        $sunday = Carbon::parse('2026-10-18 03:05', config('app.timezone'));
        Carbon::setTestNow($sunday);
        $this->artisan('servik:backups')->assertSuccessful();
        $full = Backup::firstOrFail();
        $this->assertSame(['full', 'scheduled'], [$full->type, $full->trigger]);
        app(Backups::class)->take($full);
        $this->assertTrue($this->agent->called('backup.create')[0]['chain']);
        $this->assertFalse(app(Backups::class)->due(now()), 'one backup per slot');

        $monday = $this->serverBackup('scheduled', $sunday->copy()->addDay());
        $tuesday = $this->serverBackup('scheduled', $sunday->copy()->addDays(2));
        $this->assertSame(['incremental', $monday->id], [$tuesday->type, $tuesday->base_id]);
        $this->assertTrue($this->agent->called('backup.create')[2]['incremental']);
        $this->assertSame($monday->key, $this->agent->called('backup.create')[2]['base']);

        $item = $tuesday->items->firstWhere('name', 'kahveatolyesi.com.tr');
        $chain = app(Backups::class)->chain($item);
        $this->assertSame([$full->id, $monday->id, $tuesday->id], array_map(fn ($i) => $i->backup_id, $chain));

        // Deleting the full backup takes what continues from it, with Undo.
        $page = Livewire::test(BackupIndex::class)->call('askDelete', $full->id)->assertSee('2 artımlı yedek de');
        $page->call('delete');
        $this->assertSame(3, Backup::where('status', 'deleted')->count());
        Queue::assertPushed(PurgeBackup::class, 3);
        $page->call('undoDelete', Backup::pluck('id')->all());
        $this->assertSame(0, Backup::where('status', 'deleted')->count());
        Carbon::setTestNow();
    }

    public function test_retention_keeps_the_newest_days_weeks_and_months_with_their_chains(): void
    {
        app(BackupSettings::class)->save(['keep_daily' => 2, 'keep_weekly' => 1, 'keep_monthly' => 0]);
        $start = Carbon::parse('2026-10-04 03:00', config('app.timezone'));
        $made = [];
        foreach (range(0, 9) as $day) {
            $made[] = $this->serverBackup('scheduled', $start->copy()->addDays($day));
        }
        Carbon::setTestNow();

        // The last two days stay, with the Sunday full backup they continue from.
        $kept = Backup::orderBy('id')->pluck('created_at')->map(fn ($d) => $d->format('m-d'))->all();
        $this->assertSame(['10-11', '10-12', '10-13'], $kept);
        $this->assertContains(['key' => $made[0]->key, 'local' => true], array_map(fn ($c) => array_intersect_key($c, ['key' => 1, 'local' => 1]), $this->agent->called('backup.delete')));
    }

    public function test_restoring_a_live_site_backs_it_up_first_and_can_be_undone(): void
    {
        $this->fullSite();
        $backup = $this->serverBackup();
        $item = $backup->items->firstWhere('name', 'kahveatolyesi.com.tr');
        DnsZone::where('name', 'kahveatolyesi.com.tr')->first()->records()->where('type', 'A')->update(['content' => '198.51.100.1']);

        Livewire::test(BackupIndex::class)
            ->call('openRestore', $backup->id)
            ->assertSet('restoreItemId', $item->id)
            ->assertSee('Önce şimdiki hâli yedekle')
            ->set('restoreParts', ['files', 'databases', 'dns'])
            ->call('restore')
            ->assertHasNoErrors();
        Queue::assertPushed(RestoreBackup::class);
        $restore = BackupRestore::firstOrFail();
        app(Restores::class)->run($restore);
        $restore->refresh();

        $this->assertSame('succeeded', $restore->status, (string) $restore->error);
        $safety = $restore->safetyBackup;
        $this->assertSame(['site', 'safety', 'succeeded'], [$safety->scope, $safety->trigger, $safety->status]);
        $call = $this->agent->called('backup.restore')[0];
        $this->assertSame(['files', 'home', 'databases', 'laravel'], $call['parts']);
        $this->assertSame([['key' => $backup->key, 'name' => 'kahveatolyesi.com.tr']], $call['chain']);
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_shop']], $call['databases']);
        $this->assertSame([['server' => 'mysql', 'name' => 'kahve_app', 'hosts' => ['10.0.0.5'], 'grants' => [['database' => 'kahve_shop', 'access' => 'all']]]], $call['database_users']);
        $this->assertSame('203.0.113.10', DnsZone::where('name', 'kahveatolyesi.com.tr')->first()->records()->where('type', 'A')->value('content'));
        Queue::assertPushed(SyncDnsZone::class);

        // Undo puts the safety backup back, without another one.
        Livewire::test(BackupIndex::class)->call('undoRestore', $restore->id);
        $undo = BackupRestore::latest('id')->first();
        $this->assertSame($safety->items()->value('id'), $undo->backup_item_id);
        $this->assertFalse($undo->result['safety']);
    }

    public function test_a_deleted_site_is_created_again_from_its_backup(): void
    {
        app(MailSettings::class)->saveServer(['state' => 'ready', 'setup_at' => now()->toIso8601String()]);
        $this->fullSite();
        $backup = $this->serverBackup();
        $item = $backup->items->firstWhere('name', 'kahveatolyesi.com.tr');
        // The site and everything of it is gone.
        MailDomain::query()->delete();
        DnsZone::query()->delete();
        DatabaseUser::query()->forceDelete();
        Database::query()->forceDelete();
        $this->site->delete();
        SystemUser::where('name', 'kahve')->delete();

        Livewire::test(BackupIndex::class)
            ->call('openRestore', $backup->id, $item->id)
            ->assertSee('Bu site artık yok')
            ->set('restoreParts', ['files', 'databases', 'mail', 'dns'])
            ->call('restore')->assertHasNoErrors();
        $restore = BackupRestore::firstOrFail();
        app(Restores::class)->run($restore);
        $restore->refresh();

        $this->assertSame('succeeded', $restore->status, (string) $restore->error);
        $this->assertTrue($restore->recreated);
        $this->assertNull($restore->safety_backup_id);
        $site = Site::where('domain', 'kahveatolyesi.com.tr')->firstOrFail();
        $this->assertSame('kahve', $site->systemUser->name);
        $this->assertSame('8.4', $site->php_version);
        $this->assertNotNull($site->provisioned_at);
        $this->assertSame('kahve_app', DatabaseUser::where('system_user_id', $site->system_user_id)->value('name'));
        $this->assertSame('kahve_shop', Database::where('site_id', $site->id)->value('name'));
        $this->assertSame(['info'], MailDomain::where('name', $site->domain)->first()->mailboxes()->pluck('name')->all());
        $this->assertSame(2, DnsZone::where('name', $site->domain)->first()->records()->count());
        $this->assertSame('site.apply', $this->agent->methods()[array_search('backup.restore', $this->agent->methods(), true) - 1] ?? 'site.apply');
    }

    public function test_an_incremental_with_a_missing_link_cannot_be_restored(): void
    {
        $sunday = Carbon::parse('2026-10-18 03:00', config('app.timezone'));
        $full = $this->serverBackup('scheduled', $sunday);
        $monday = $this->serverBackup('scheduled', $sunday->copy()->addDay());
        Carbon::setTestNow();
        $full->update(['status' => 'deleted']);

        $item = $monday->items->firstWhere('name', 'kahveatolyesi.com.tr');
        $this->assertStringContainsString('dayandığı önceki yedek artık yok', app(Restores::class)->start($item, ['files'], true, null));
    }

    public function test_customers_back_up_and_restore_only_their_own_sites_within_the_package(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $customer->package->update(['max_backups' => 1]);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'klinikmavi.com', 'provisioned_at' => now()]);
        $server = $this->serverBackup();
        $this->actingAs($customer->user);

        $this->get('/yedekler')->assertOk()->assertSee('klinikmavi.com')->assertDontSee('kahveatolyesi.com.tr')->assertDontSee('Hedefler');
        $this->get('/yedekler?sekme=hedefler')->assertOk()->assertDontSee('Hedef ekle');
        Livewire::test(BackupIndex::class)->call('openTarget')->assertForbidden();
        Livewire::test(BackupIndex::class)->call('openImport')->assertForbidden();

        // Not someone else's site, and not a server backup.
        Livewire::test(BackupIndex::class)->call('openBackup')->set('backupScope', 'server')->set('backupSite', $this->site->id)
            ->call('startBackup')->assertHasErrors('backupSite');
        $this->assertSame(1, Backup::count());
        Livewire::test(BackupIndex::class)->call('openBackup')->assertSet('backupSite', $mine->id)->call('startBackup')->assertHasNoErrors();
        $own = Backup::latest('id')->first();
        $this->assertSame(['site', $customer->id], [$own->scope, $own->customer_id]);
        app(Backups::class)->take($own);
        $this->assertSame('1 / 1', app(Quotas::class)->report($customer->fresh())['backups']['text']);
        $this->assertStringContainsString('yedek sınırına', (string) app(Backups::class)->startSite($mine, $customer->user));

        // Their archive of the server backup, yes; the other site's, no.
        $theirs = $server->items->firstWhere('name', 'kahveatolyesi.com.tr');
        $this->assertSame([], BackupItem::visibleTo($customer->user)->whereKey($theirs->id)->pluck('id')->all());
        $this->get(route('backups.download', $theirs))->assertNotFound();
        $this->get(route('backups.download', $server->items->firstWhere('name', 'panel')))->assertNotFound();
        $this->assertSame('Bu yedek sizin sitelerinizden birine ait değil.', app(Restores::class)->refusal($customer->user, $theirs, $this->site));
        Livewire::test(BackupIndex::class)->call('openRestore', $own->id)->assertSee('klinikmavi.com')
            ->set('restoreParts', ['files'])->call('restore')->assertHasNoErrors();

        // They delete their own backups, not the server's.
        Livewire::test(BackupIndex::class)->call('askDelete', $server->id)->assertSet('deleteId', null);
        Livewire::test(BackupIndex::class)->call('askDelete', $own->id)->assertSet('deleteId', $own->id)->call('delete');
        $this->assertSame('deleted', $own->fresh()->status);
    }

    public function test_a_package_without_backups_has_no_page_and_its_sites_are_left_out(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'klinik']);
        $customer->package->update(['allow_backups' => false]);
        Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'klinikmavi.com', 'provisioned_at' => now()]);

        $this->serverBackup();
        $this->assertSame(['kahveatolyesi.com.tr', 'panel'], array_column($this->agent->called('backup.create')[0]['items'], 'name'));

        $this->actingAs($customer->user);
        $this->get('/yedekler')->assertForbidden();
        $this->get('/')->assertDontSee('Yedekler');
    }

    public function test_downloads_stream_the_archive_and_remote_only_ones_are_fetched_first(): void
    {
        $backup = $this->serverBackup();
        $item = $backup->items->firstWhere('name', 'kahveatolyesi.com.tr');
        $this->agent->answers['backup.read'] = ['data' => base64_encode('TAR'), 'offset' => 3, 'size' => 3];

        $response = $this->get(route('backups.download', $item));
        $response->assertOk();
        $this->assertSame('TAR', $response->streamedContent());
        $this->assertStringContainsString('kahveatolyesi.com.tr-', $response->headers->get('content-disposition'));

        $target = BackupTarget::create(['name' => 'B2', 'type' => 's3', 'config' => ['bucket' => 'yedek', 'access_key' => 'a', 'secret_key' => 'b'], 'status' => 'ok']);
        $backup->update(['local' => false, 'remote' => true, 'target_id' => $target->id]);
        $this->get(route('backups.download', $item))->assertNotFound();
        Livewire::test(BackupIndex::class)->call('fetch', $backup->id);
        $this->assertSame(['kahveatolyesi.com.tr', 'panel'], $this->agent->called('backup.fetch')[0]['names']);
        $this->assertNotNull($backup->fresh()->cached_at);
        $this->get(route('backups.download', $item))->assertOk();

        // The fetched copy goes after a day.
        $this->travel(25)->hours();
        app(Backups::class)->dropCaches();
        $this->assertNull($backup->fresh()->cached_at);
    }

    public function test_an_archive_from_another_server_is_imported_and_an_incremental_alone_is_refused(): void
    {
        $this->agent->answers['backup.import'] = fn ($p) => ['manifest' => ['version' => 1, 'kind' => 'site', 'name' => 'eski.example.com', 'server' => 'srv-old', 'created' => '2026-10-10T03:00:00Z', 'parts' => ['files' => 10]], 'meta' => ['version' => 1, 'site' => ['domain' => 'eski.example.com']], 'size' => 2048];

        Livewire::test(BackupIndex::class)->call('openImport')->set('importFrom', 'folder')->call('importFile', 'eski.example.com.tar')->assertHasNoErrors();

        $backup = Backup::firstOrFail();
        $this->assertSame(['import', 'srv-old', 'site'], [$backup->trigger, $backup->source_host, $backup->scope]);
        $this->assertSame('eski.example.com', $backup->items()->value('name'));
        $this->assertSame(['files'], $backup->items()->first()->restorable());

        $this->agent->answers['backup.import'] = ['manifest' => ['version' => 1, 'kind' => 'site', 'name' => 'eski.example.com', 'incremental' => ['files']], 'size' => 10];
        Livewire::test(BackupIndex::class)->call('openImport')->call('importFile', 'artimli.tar')->assertHasErrors('importPassphrase');
        $this->assertSame(1, Backup::count());
    }

    public function test_backups_found_on_a_target_are_listed_with_their_chain(): void
    {
        $target = BackupTarget::create(['name' => 'B2', 'type' => 's3', 'config' => ['bucket' => 'yedek', 'access_key' => 'a', 'secret_key' => 'b', 'folder' => 'servik'], 'status' => 'ok']);
        $d = fn ($key, $created, $incr) => ['path' => 'srv-old', 'encrypted' => true, 'descriptor' => ['version' => 1, 'key' => $key, 'server' => 'srv-old', 'scope' => 'server', 'created' => $created, 'incremental' => $incr, 'encrypted' => true,
            'items' => [['name' => 'eski.example.com', 'size' => 100, 'parts' => ['files' => 100], 'incremental' => $incr ? ['files'] : []]]]];
        $this->agent->answers['backup.scan'] = [$d('k3', '2026-10-13T03:00:00Z', true), $d('k2', '2026-10-12T03:00:00Z', true), $d('k1', '2026-10-11T03:00:00Z', false), $d('k0', '2026-10-04T03:00:00Z', false)];

        $page = Livewire::test(BackupIndex::class)->call('openImport')->set('importFrom', 'target')->set('scanTargetId', $target->id)->call('scanTarget')->assertSee('srv-old');
        $this->assertSame('servik', $this->agent->called('backup.scan')[0]['target']['prefix']);
        $page->call('adopt', 'k3')->assertHasErrors('importPassphrase');
        $page->set('importPassphrase', 'eski-sunucunun-parolasi')->call('adopt', 'k3')->assertHasNoErrors();

        $this->assertSame(['k1', 'k2', 'k3'], Backup::orderBy('id')->pluck('key')->all());
        $item = Backup::where('key', 'k3')->first()->items()->first();
        $this->assertSame(['k1', 'k2', 'k3'], array_map(fn ($i) => $i->backup->key, app(Backups::class)->chain($item)));
        $ref = app(Backups::class)->chainRef($item);
        $this->assertSame('servik/srv-old', $ref['target']['prefix']);
        $this->assertSame('eski-sunucunun-parolasi', Backup::where('key', 'k3')->first()->passphrase);
    }

    public function test_the_schedule_is_saved_with_undo(): void
    {
        Livewire::test(BackupIndex::class)
            ->set('sFrequency', 'weekly')->set('sWeekday', 6)->set('sTime', '02:30')->set('sKeepDaily', 3)
            ->set('sExclude', "*/cache/*\n*.log")
            ->call('saveSchedule')->assertHasNoErrors();

        $s = app(BackupSettings::class)->schedule();
        $this->assertSame(['weekly', 6, '02:30', 3, ['*/cache/*', '*.log']], [$s['frequency'], $s['weekday'], $s['time'], $s['keep_daily'], $s['exclude']]);
        $this->assertSame('Her cumartesi 02:30 (ayın ilki tam, diğerleri artımlı)', app(BackupSettings::class)->summary());

        Livewire::test(BackupIndex::class)->set('sTime', '25:00')->call('saveSchedule')->assertHasErrors('sTime');
        Livewire::test(BackupIndex::class)->call('restoreSchedule', BackupSettings::DEFAULTS);
        $this->assertSame('daily', app(BackupSettings::class)->schedule()['frequency']);
        $this->assertNotNull(Setting::get('backups.schedule'));
    }

    public function test_the_site_page_links_its_backups(): void
    {
        $this->serverBackup();

        $this->get('/sites/'.$this->site->id)->assertOk()->assertSee('Son yedek')->assertSee(route('backups.index', ['site' => $this->site->id]), false);
        $this->get('/yedekler?site='.$this->site->id)->assertOk()->assertSee('sitesinin yedekleri')->assertSee('İndir');
    }
}
