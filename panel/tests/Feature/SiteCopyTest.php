<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\CopySite;
use App\Livewire\Sites\SiteCopyTool;
use App\Models\Backup;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteCopy;
use App\Models\User;
use App\Models\WordPressInstall;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Sites\SiteCopies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Siteyi kopyala: a staging copy with its own databases, refreshed from
 * the live site and published back onto it with a backup first.
 */
class SiteCopyTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        // The copy's own jobs run in the test; what they dispatch runs at once.
        Queue::fake([CopySite::class]);
        $this->agent = new FakeAgent([
            'site.apply' => fn ($p) => ['uid' => 1001, 'home' => '/home/kahve', 'document_root' => '/home/kahve/web/'.$p['domain'].'/public_html'],
            'site.copy' => ['bytes' => 1024, 'notes' => [['code' => 'db_not_in_config', 'subject' => 'kahve_old']], 'ms' => 900],
            'backup.create' => fn ($p) => [
                'key' => $p['key'],
                'items' => array_map(fn ($i) => ['name' => $i['name'], 'size' => 1000, 'parts' => ['files' => 600, 'databases' => 300], 'skipped' => [], 'incremental' => [], 'warnings' => []], $p['items']),
                'size' => 1000, 'local' => true, 'remote' => false, 'encrypted' => false, 'seconds' => 3,
            ],
            'backup.restore' => ['restored' => ['files', 'databases'], 'skipped' => []],
            'backup.run.read' => ['data' => "▸ Kopyalama başladı\n✓ Kopyalama bitti.\n", 'offset' => 40, 'size' => 40],
            'wordpress.status' => ['version' => '6.8.3', 'core_update' => '', 'plugins' => [], 'themes' => []],
            'dns.zone.apply' => [],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'aliases' => ['www.kahveatolyesi.com.tr'], 'provisioned_at' => now(), 'php_version' => '8.3']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    private function database(): Database
    {
        $db = Database::factory()->forSite($this->site, 'shop')->create();
        $user = DatabaseUser::create(['server' => 'mysql', 'name' => 'kahve_app', 'system_user_id' => $this->site->system_user_id, 'hosts' => []]);
        $user->databases()->attach($db->id, ['access' => 'read']);

        return $db;
    }

    private function runQueued(): SiteCopy
    {
        $run = SiteCopy::latest('id')->first();
        app(SiteCopies::class)->run($run);

        return $run->fresh();
    }

    public function test_the_site_tools_link_it_and_a_name_is_suggested(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.copy', $this->site))
            ->assertSee('Kopya yok');
        Site::factory()->create(['domain' => 'staging.kahveatolyesi.com.tr']);

        Livewire::test(SiteCopyTool::class, ['site' => $this->site->id])
            ->assertSet('domain', 'staging2.kahveatolyesi.com.tr')
            ->assertSet('noindex', true)
            ->assertSee('Kopyayı oluştur');
    }

    public function test_a_copy_gets_its_own_site_databases_and_addresses(): void
    {
        $db = $this->database();
        Setting::put('dns.ipv4', '203.0.113.20');
        $zone = DnsZone::factory()->create(['name' => 'kahveatolyesi.com.tr', 'site_id' => $this->site->id]);
        WordPressInstall::create(['site_id' => $this->site->id, 'path' => 'public_html', 'status' => WordPressInstall::ACTIVE]);

        Livewire::test(SiteCopyTool::class, ['site' => $this->site->id])
            ->set('domain', 'Staging.KahveAtolyesi.com.tr')
            ->call('create')
            ->assertHasNoErrors();
        Queue::assertPushedOn('backups', CopySite::class);
        $copy = Site::where('domain', 'staging.kahveatolyesi.com.tr')->firstOrFail();
        $this->assertSame($this->site->id, $copy->copy_of_id);
        $this->assertSame($this->site->system_user_id, $copy->system_user_id);
        $this->assertSame('8.3', $copy->php_version);
        $this->assertTrue($copy->noindex);
        $this->assertTrue($zone->records()->where('name', 'staging')->where('type', 'A')->exists());

        $run = $this->runQueued();
        $this->assertSame('succeeded', $run->status, (string) $run->error);
        $this->assertSame(SiteStatus::Active, $copy->fresh()->status);
        $copyDb = Database::where('site_id', $copy->id)->firstOrFail();
        $this->assertSame('kahve_shop_copy', $copyDb->name);
        $this->assertSame($db->id, $copyDb->copy_of_id);
        $this->assertSame('read', $copyDb->users()->first()->pivot->access);

        $spec = $this->agent->called('site.copy')[0];
        $this->assertSame(['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.3'], $spec['from']);
        $this->assertSame('staging.kahveatolyesi.com.tr', $spec['to']['domain']);
        $this->assertTrue($spec['files']);
        $this->assertSame([['server' => 'mysql', 'from' => 'kahve_shop', 'to' => 'kahve_shop_copy']], $spec['databases']);
        $this->assertSame([['www.kahveatolyesi.com.tr', 'staging.kahveatolyesi.com.tr'], ['kahveatolyesi.com.tr', 'staging.kahveatolyesi.com.tr']], $spec['hosts']);
        $this->assertSame('public_html', $spec['wordpress']);
        $this->assertSame('0', $spec['public']);
        // The copy's site config asks search engines to stay away.
        $this->assertTrue(collect($this->agent->called('site.apply'))->contains(fn ($p) => $p['domain'] === $copy->domain && ($p['noindex'] ?? false)));
        $this->assertTrue($copy->wordpress()->exists());

        Livewire::test(SiteCopyTool::class, ['site' => $this->site->id])
            ->assertSee('staging.kahveatolyesi.com.tr')
            ->assertSee('kahve_old veritabanının adı ayar dosyalarında bulunamadı');
    }

    public function test_a_copy_that_fails_is_removed_with_its_databases(): void
    {
        $this->database();
        $this->agent->answers['site.copy'] = AgentException::rpcError('site.copy', AgentException::OPERATION_FAILED, 'Diskte yer yok.');
        $this->agent->answers['site.delete'] = ['user_removed' => false];

        app(SiteCopies::class)->create($this->site, 'staging.kahveatolyesi.com.tr', true, true, auth()->user());
        $run = $this->runQueued();

        $this->assertSame('failed', $run->status);
        $this->assertNull($run->site_id, (string) $run->error.json_encode($this->agent->methods()));
        $this->assertSame('staging.kahveatolyesi.com.tr', $run->domain);
        $this->assertFalse(Site::where('domain', 'staging.kahveatolyesi.com.tr')->exists());
        $this->assertFalse(Database::withTrashed()->where('name', 'kahve_shop_copy')->exists());
        $this->assertTrue(Database::where('name', 'kahve_shop')->exists());
        $this->assertTrue(DatabaseUser::where('name', 'kahve_app')->exists());

        Livewire::test(SiteCopyTool::class, ['site' => $this->site->id])
            ->call('show', $run->id)
            ->assertSee('Diskte yer yok.');
    }

    public function test_refresh_copies_the_live_site_again(): void
    {
        $this->database();
        app(SiteCopies::class)->create($this->site, 'staging.kahveatolyesi.com.tr', true, false, auth()->user());
        $this->runQueued();
        $copy = Site::where('domain', 'staging.kahveatolyesi.com.tr')->firstOrFail();

        Livewire::test(SiteCopyTool::class, ['site' => $copy->id])
            ->assertSee('Kaynaktan yenile')
            ->call('openRun', 'refresh')
            ->set('parts', ['databases'])
            ->call('start')
            ->assertHasNoErrors();
        $run = $this->runQueued();

        $this->assertSame(SiteCopy::REFRESH, $run->kind);
        $this->assertSame('succeeded', $run->status);
        $spec = $this->agent->called('site.copy')[1];
        $this->assertFalse($spec['files']);
        $this->assertSame('kahve_shop_copy', $spec['databases'][0]['to']);
        $this->assertSame('', $spec['public']);
        $this->assertSame(1, Database::where('site_id', $copy->id)->count());
    }

    public function test_publish_backs_the_live_site_up_and_can_be_undone(): void
    {
        $this->database();
        app(SiteCopies::class)->create($this->site, 'staging.kahveatolyesi.com.tr', true, true, auth()->user());
        $this->runQueued();
        $copy = Site::where('domain', 'staging.kahveatolyesi.com.tr')->firstOrFail();

        Livewire::test(SiteCopyTool::class, ['site' => $copy->id])
            ->call('openRun', 'publish')
            ->call('start');
        $run = $this->runQueued();

        $this->assertSame('succeeded', $run->status);
        $backup = Backup::findOrFail($run->backup_id);
        $this->assertSame('safety', $backup->trigger);
        $spec = $this->agent->called('site.copy')[1];
        $this->assertSame('staging.kahveatolyesi.com.tr', $spec['from']['domain']);
        $this->assertSame('kahveatolyesi.com.tr', $spec['to']['domain']);
        $this->assertSame([['server' => 'mysql', 'from' => 'kahve_shop_copy', 'to' => 'kahve_shop']], $spec['databases']);
        $this->assertSame([['staging.kahveatolyesi.com.tr', 'kahveatolyesi.com.tr']], $spec['hosts']);
        $this->assertSame('keep', $spec['public']);
        // The backup ran before the copy.
        $methods = $this->agent->methods();
        $this->assertLessThan(array_keys($methods, 'site.copy')[1], array_search('backup.create', $methods, true));

        Livewire::test(SiteCopyTool::class, ['site' => $copy->id])
            ->assertSee('Geri al')
            ->call('undo', $run->id);
        $run->refresh();
        $this->assertSame('undone', $run->status);
        $this->assertSame(['files', 'databases'], $run->restore->parts);
    }

    public function test_a_failed_publish_puts_the_live_site_back(): void
    {
        $this->database();
        app(SiteCopies::class)->create($this->site, 'staging.kahveatolyesi.com.tr', true, true, auth()->user());
        $this->runQueued();
        $copy = Site::where('domain', 'staging.kahveatolyesi.com.tr')->firstOrFail();
        $this->agent->answers['site.copy'] = AgentException::rpcError('site.copy', AgentException::OPERATION_FAILED, 'Veritabanı yüklenemedi.');

        app(SiteCopies::class)->start($copy, SiteCopy::PUBLISH, SiteCopy::PARTS, auth()->user());
        $run = $this->runQueued();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('Canlı site aktarmadan önceki hâline döndürüldü.', $run->error);
        $this->assertSame('succeeded', $run->restore->status);
        $this->assertNotEmpty($this->agent->called('backup.restore'));
    }

    public function test_laravel_sites_are_not_published_over_and_copies_are_not_copied(): void
    {
        app(SiteCopies::class)->create($this->site, 'staging.kahveatolyesi.com.tr', false, true, auth()->user());
        $this->runQueued();
        $copy = Site::where('domain', 'staging.kahveatolyesi.com.tr')->firstOrFail();
        LaravelApp::factory()->create(['site_id' => $this->site->id]);

        $copies = app(SiteCopies::class);
        $this->assertStringContainsString('Laravel', $copies->runRefusal($copy->fresh(), SiteCopy::PUBLISH));
        $this->assertNull($copies->runRefusal($copy->fresh(), SiteCopy::REFRESH));
        $this->assertStringContainsString('zaten bir kopya', $copies->createRefusal(auth()->user(), $copy->fresh()));
    }

    public function test_customers_copy_their_own_sites_within_their_package(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com', 'provisioned_at' => now()]);
        Database::factory()->forSite($mine, 'wp')->create();
        $customer->package->update(['max_sites' => 2, 'max_databases' => 1]);
        $this->actingAs($customer->user);

        $this->get(route('sites.copy', $this->site))->assertNotFound();
        Livewire::test(SiteCopyTool::class, ['site' => $mine->id])
            ->assertSee('veritabanı sınırına')
            ->call('create')
            ->assertHasErrors('domain');
        $this->assertFalse(Site::where('domain', 'staging.adapansiyon.com')->exists());

        $customer->package->update(['max_databases' => 2]);
        Livewire::test(SiteCopyTool::class, ['site' => $mine->id])
            ->call('create')
            ->assertHasNoErrors();
        $this->assertTrue(Site::where('domain', 'staging.adapansiyon.com')->exists());
    }
}
