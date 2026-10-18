<?php

namespace Tests\Feature;

use App\Enums\ZoneStatus;
use App\Jobs\DeleteDnsZone;
use App\Jobs\SetupDnsServer;
use App\Jobs\SyncDnsZone;
use App\Livewire\Dns\ZoneIndex;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ZoneIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $this->agent();
    }

    /**
     * @param  array<string, mixed>|null  $status  dns.status result; null means the agent is down
     */
    private function agent(?array $status = ['installed' => true, 'configured' => true, 'running' => true, 'version' => '4.8.3']): void
    {
        $agent = Mockery::mock(AgentClient::class);
        if ($status === null) {
            $agent->shouldReceive('call')->andThrow(AgentException::unavailable('/run/servik-agent/agent.sock', 'No such file or directory'));
        } else {
            $agent->shouldReceive('call')->with('dns.status')->andReturn($status);
            $agent->shouldReceive('call')->with('system.addresses')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => []]);
        }
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_lists_zones_with_their_site(): void
    {
        $site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        DnsZone::factory()->withRecords()->create(['name' => 'kahveatolyesi.com.tr', 'site_id' => $site->id]);
        DnsZone::factory()->create(['name' => 'yalnizdns.com', 'status' => ZoneStatus::Failed]);

        $this->get('/dns')
            ->assertOk()
            ->assertSee('kahveatolyesi.com.tr')
            ->assertSee('yalnizdns.com')
            ->assertSee('Yalnızca DNS')
            ->assertSee('Bu sunucuda')
            ->assertSee('Hata')
            ->assertDontSee('DNS sunucusu kurulu değil');
    }

    public function test_filters_and_searches(): void
    {
        DnsZone::factory()->create(['name' => 'kahve.com.tr']);
        DnsZone::factory()->create(['name' => 'bozuk.com', 'status' => ZoneStatus::Failed]);

        Livewire::test(ZoneIndex::class)
            ->set('filter', 'problem')
            ->assertSee('bozuk.com')
            ->assertDontSee('kahve.com.tr')
            ->set('filter', '')
            ->set('search', 'kahve')
            ->assertSee('kahve.com.tr')
            ->assertDontSee('bozuk.com');
    }

    public function test_adds_a_dns_only_domain_from_the_template(): void
    {
        Livewire::test(ZoneIndex::class)
            ->call('openCreate')
            ->set('domain', 'Yeni-Alan.com ')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('creating', false)
            ->assertDispatched('toast');

        $zone = DnsZone::sole();
        $this->assertSame('yeni-alan.com', $zone->name);
        $this->assertNull($zone->site_id);
        $this->assertSame(ZoneStatus::Syncing, $zone->status);
        $this->assertTrue($zone->records->contains(fn ($r) => $r->name === '@' && $r->type === 'A' && $r->content === '203.0.113.10'));
        Queue::assertPushed(SyncDnsZone::class);
    }

    public function test_adds_an_empty_zone(): void
    {
        Livewire::test(ZoneIndex::class)
            ->call('openCreate')
            ->set('domain', 'bos.com')
            ->set('start', 'empty')
            ->call('create');

        $this->assertSame(['NS'], DnsZone::sole()->records->where('name', '@')->pluck('type')->unique()->values()->all());
    }

    public function test_validates_the_domain(): void
    {
        DnsZone::factory()->create(['name' => 'var.com']);

        Livewire::test(ZoneIndex::class)
            ->call('openCreate')
            ->set('domain', 'not a domain')
            ->call('create')
            ->assertHasErrors('domain')
            ->set('domain', 'var.com')
            ->call('create')
            ->assertHasErrors(['domain' => 'unique']);
    }

    public function test_deletes_selected_zones_and_can_undo(): void
    {
        $a = DnsZone::factory()->withRecords()->create(['name' => 'a.com']);
        $b = DnsZone::factory()->withRecords()->create(['name' => 'b.com']);

        $component = Livewire::test(ZoneIndex::class)
            ->set('selected', [$a->id, $b->id])
            ->call('confirmDelete')
            ->assertSet('confirmingDelete', true)
            ->assertSee('a.com, b.com')
            ->call('deleteSelected')
            ->assertDispatched('toast', fn ($name, $params) => $params['message'] === '2 DNS bölgesi siliniyor.');

        $this->assertSame(ZoneStatus::Deleting, $a->fresh()->status);
        Queue::assertPushed(DeleteDnsZone::class, 2);

        // The job runs for a.com before the user clicks "Geri al".
        $a->delete();
        $tokens = $component->effects['dispatches'][0]['params']['undo']['args'][0];
        $component->call('undoDelete', $tokens);

        $this->assertSame(['a.com', 'b.com'], DnsZone::orderBy('name')->pluck('name')->all());
        $this->assertSame(4, DnsZone::where('name', 'a.com')->sole()->records()->count());
        $this->assertSame(ZoneStatus::Syncing, $b->fresh()->status);
    }

    public function test_offers_to_install_powerdns(): void
    {
        $this->agent(['installed' => false, 'configured' => false, 'running' => false]);

        Livewire::test(ZoneIndex::class)
            ->assertSee('DNS sunucusu kurulu değil')
            ->call('setupServer')
            ->assertSee('DNS sunucusu kuruluyor');

        Queue::assertPushed(SetupDnsServer::class);
        $this->assertSame('installing', Setting::get('dns.server')['state']);
    }

    public function test_shows_a_stopped_server_and_an_unreachable_agent(): void
    {
        $this->agent(['installed' => true, 'configured' => true, 'running' => false, 'error' => 'connection refused']);
        Livewire::test(ZoneIndex::class)->assertSee('DNS sunucusu yanıt vermiyor')->assertSee('connection refused');

        $this->agent(null);
        Livewire::test(ZoneIndex::class)->assertSee('Sunucu ajanına ulaşılamıyor');
    }
}
