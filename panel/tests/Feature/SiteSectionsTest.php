<?php

namespace Tests\Feature;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Livewire\Backups\BackupIndex;
use App\Livewire\Dns\ZoneShow;
use App\Livewire\Sites\SiteDns;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Customers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * The sections of a site that used to open the server-wide pages: DNS
 * records, databases and backups, now shown under the site's header.
 */
class SiteSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        Setting::put('dns.ipv4', '203.0.113.10');
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andReturn([])->byDefault();
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_dns_records_are_managed_inside_the_site(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr', 'site_id' => $site->id]);

        $this->get(route('sites.dns', $site))->assertOk()
            ->assertSee('Site bölümleri')->assertSee('ns1.example.net')->assertSee('Kayıt ekle')
            // The zone page's own title is left out inside the site.
            ->assertDontSee('Bu alan adının DNS kayıtlarını ve bölge ayarlarını yönetin.');
        $this->get(route('sites.show', $site))->assertOk()->assertSee(route('sites.dns', $site))->assertSee('4 kayıt');

        // Deleting the zone inside the site returns to the site's DNS page.
        Livewire::test(ZoneShow::class, ['zone' => $zone->id, 'siteId' => $site->id])
            ->tap(fn () => $zone->delete())
            ->call('refreshStatus')
            ->assertRedirect(route('sites.dns', $site));
    }

    public function test_a_subdomain_site_shows_its_parent_zone(): void
    {
        $site = Site::factory()->create(['domain' => 'panel.kahve.com.tr']);
        DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);

        $this->get(route('sites.dns', $site))->assertOk()->assertSee('üst alan adının bölgesinde')->assertSee('ns1.example.net');
    }

    public function test_a_site_whose_dns_is_elsewhere_lists_the_records_and_can_start_a_zone(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr', 'aliases' => ['www.kahve.com.tr']]);

        $this->get(route('sites.dns', $site))->assertOk()
            ->assertSee('bu sunucuda tutulmuyor')->assertSee('DNS bölgesi oluştur')
            ->assertSee('www.kahve.com.tr')->assertSee('203.0.113.10');
        $this->get(route('sites.show', $site))->assertOk()->assertSee('Bölge bu sunucuda değil');

        $page = Livewire::test(SiteDns::class, ['site' => $site->id])->call('createZone')->assertDispatched('toast');
        $zone = DnsZone::where('name', 'kahve.com.tr')->sole();
        $this->assertSame($site->id, $zone->site_id);
        Queue::assertPushed(SyncDnsZone::class);

        $page->call('undoCreate', $zone->id);
        $this->assertSame(ZoneStatus::Deleting, $zone->fresh()->status);
    }

    public function test_databases_and_backups_open_inside_the_site(): void
    {
        $site = Site::factory()->create(['domain' => 'kahve.com.tr']);
        $other = Site::factory()->create(['domain' => 'baska.com.tr']);
        Database::factory()->forSite($site, 'shop')->create();
        Database::factory()->forSite($other, 'blog')->create();

        $this->get(route('sites.databases', $site))->assertOk()
            ->assertSee('Site bölümleri')->assertSee('_shop')->assertDontSee('_blog')->assertDontSee('Tümünü göster');

        $this->get(route('sites.backups', $site))->assertOk()->assertSee('Site bölümleri')->assertDontSee('Tümünü göster');
        // Destinations and the schedule belong to the whole server.
        Livewire::withQueryParams(['tab' => 'schedule'])->test(BackupIndex::class, ['site' => $site->id])->assertSet('tab', 'backups');
    }

    public function test_site_sections_follow_the_package_and_the_owner(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $customer->package->update(['allow_dns' => false]);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'kahve.com.tr']);
        $other = Site::factory()->create(['domain' => 'baska.com.tr']);

        $this->actingAs($customer->user->fresh());
        $this->get(route('sites.dns', $site))->assertForbidden();
        // Without DNS the section opens the site's names instead.
        $this->get(route('sites.show', $site))->assertOk()->assertSee(route('sites.domains', $site))->assertDontSee(route('sites.dns', $site));
        $this->get(route('sites.databases', $site))->assertOk();
        $this->get(route('sites.databases', $other))->assertNotFound();
        $this->get(route('sites.backups', $other))->assertNotFound();
    }
}
