<?php

namespace Tests\Feature;

use App\Jobs\SyncDnsZone;
use App\Livewire\Dns\DnsSettingsPage;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\ZoneTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class DnsSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('system.addresses')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::10']]);
        $this->app->instance(AgentClient::class, $agent);
    }

    public function test_page_shows_detected_addresses_and_the_template(): void
    {
        $this->get('/dns/settings')
            ->assertOk()
            ->assertSee('Sunucu DNS ayarları')
            ->assertSee('203.0.113.10')
            ->assertSee('ns1.ornek.com.tr, ns2.ornek.com.tr')
            ->assertSee('&lt;ip4&gt;', false);
    }

    public function test_saves_name_servers_and_updates_zones_that_used_the_old_ones(): void
    {
        $old = DnsZone::factory()->create(['name' => 'kahve.com.tr']);
        $old->records()->createMany([
            ['name' => '@', 'type' => 'NS', 'content' => 'ns1.kahve.com.tr.', 'ttl' => 3600],
            ['name' => '@', 'type' => 'NS', 'content' => 'ns2.kahve.com.tr.', 'ttl' => 3600],
        ]);
        $custom = DnsZone::factory()->create(['name' => 'baska.com']);
        $custom->records()->create(['name' => '@', 'type' => 'NS', 'content' => 'ns.cloudflare.com.', 'ttl' => 3600]);

        Livewire::test(DnsSettingsPage::class)
            ->set('nameservers', 'NS1.serfex.net.tr., ns2.serfex.net.tr')
            ->set('applyToZones', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('nameservers', 'ns1.serfex.net.tr, ns2.serfex.net.tr')
            ->assertDispatched('toast', fn ($n, $p) => str_contains($p['message'], '1 bölgenin'));

        $this->assertSame(['ns1.serfex.net.tr', 'ns2.serfex.net.tr'], app(DnsSettings::class)->nameservers());
        $this->assertSame(['ns1.serfex.net.tr.', 'ns2.serfex.net.tr.'], $old->fresh()->nameservers());
        $this->assertSame(['ns.cloudflare.com.'], $custom->fresh()->nameservers());
        Queue::assertPushed(SyncDnsZone::class, 1);
    }

    public function test_a_name_server_on_another_address_moves_its_a_record(): void
    {
        $zone = DnsZone::factory()->create(['name' => 'serfex.net.tr']);
        $zone->records()->createMany([
            ['name' => 'ns1', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600],
            ['name' => 'ns2', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600],
            ['name' => 'ns2', 'type' => 'AAAA', 'content' => '2001:db8::10', 'ttl' => 3600],
        ]);
        $records = fn () => $zone->records()->where('name', 'like', 'ns%')->orderBy('name')->orderBy('type')->get()->map(fn ($r) => $r->name.' '.$r->type.' '.$r->content)->all();

        $page = Livewire::test(DnsSettingsPage::class)
            ->set('nameservers', 'ns1.serfex.net.tr, ns2.serfex.net.tr')
            ->assertSet('nameserverIps', [['host' => 'ns1.serfex.net.tr', 'ip' => ''], ['host' => 'ns2.serfex.net.tr', 'ip' => '']])
            ->assertSee('Ad sunucularının IP adresleri')
            ->set('nameserverIps.1.ip', '198.51.100')
            ->call('save')
            ->assertHasErrors('nameserverIps.1.ip')
            ->set('nameserverIps.1.ip', '198.51.100.20')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => str_contains($p['message'], '1 bölgede'));

        $this->assertSame(['ns2.serfex.net.tr' => '198.51.100.20'], app(DnsSettings::class)->nameserverIps());
        $this->assertSame(['ns1 A 203.0.113.10', 'ns2 A 198.51.100.20'], $records());

        // New zones under the same name get it too.
        $template = app(ZoneTemplate::class)->records('serfex.net.tr', true);
        $this->assertContains(['name' => 'ns2', 'type' => 'A', 'content' => '198.51.100.20', 'priority' => null, 'ttl' => 3600], $template);
        $this->assertEmpty(array_filter($template, fn ($r) => $r['name'] === 'ns2' && $r['type'] === 'AAAA'));

        // The names follow the field; emptying the address points it back here.
        $page->set('nameservers', 'ns2.serfex.net.tr, ns1.serfex.net.tr')
            ->assertSet('nameserverIps', [['host' => 'ns2.serfex.net.tr', 'ip' => '198.51.100.20'], ['host' => 'ns1.serfex.net.tr', 'ip' => '']])
            ->set('nameserverIps.0.ip', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame([], app(DnsSettings::class)->nameserverIps());
        $this->assertSame(['ns1 A 203.0.113.10', 'ns2 A 203.0.113.10', 'ns2 AAAA 2001:db8::10'], $records());
    }

    public function test_validates_settings(): void
    {
        Livewire::test(DnsSettingsPage::class)
            ->set('nameservers', 'ns1.serfex.net.tr')
            ->call('save')
            ->assertHasErrors('nameservers')
            ->set('nameservers', 'ns1, ns2')
            ->call('save')
            ->assertHasErrors('nameservers')
            ->set('nameservers', '')
            ->set('ipv4', '2001:db8::1')
            ->call('save')
            ->assertHasErrors('ipv4');
    }

    public function test_edits_the_template_with_placeholders_and_can_undo(): void
    {
        $component = Livewire::test(DnsSettingsPage::class)
            ->call('openAdd')
            ->set('recordType', 'MX')
            ->set('recordValue', 'mail.<domain>')
            ->set('recordPriority', '10')
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->call('openAdd')
            ->set('recordType', 'A')
            ->set('recordName', 'mail')
            ->set('recordValue', '<ip4>')
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->call('openAdd')
            ->set('recordType', 'AAAA')
            ->set('recordName', 'v6')
            ->set('recordValue', '<ip4>')
            ->call('saveRecord')
            ->assertHasErrors('recordValue')
            ->set('recordType', 'NS')
            ->set('recordName', '')
            ->set('recordValue', 'ns3.serfex.net.tr')
            ->call('saveRecord')
            ->assertHasErrors('recordName');

        $rows = collect(app(DnsSettings::class)->template());
        $this->assertTrue($rows->contains(fn ($r) => $r['type'] === 'MX' && $r['content'] === 'mail.<domain>.' && $r['priority'] === 10));
        $this->assertTrue($rows->contains(fn ($r) => $r['type'] === 'A' && $r['name'] === 'mail' && $r['content'] === '<ip4>'));

        $index = $rows->search(fn ($r) => $r['type'] === 'MX');
        $component->call('deleteRow', $index);
        $this->assertCount(4, app(DnsSettings::class)->template());
        $undo = collect($component->effects['dispatches'])->last()['params']['undo'];
        $component->call($undo['method'], ...$undo['args']);
        $this->assertCount(5, app(DnsSettings::class)->template());

        $component->call('restoreDefaults');
        $this->assertEquals(DnsSettings::DEFAULT_TEMPLATE, app(DnsSettings::class)->template());
    }
}
