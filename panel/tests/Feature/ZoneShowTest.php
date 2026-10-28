<?php

namespace Tests\Feature;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Livewire\Dns\ZoneShow;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Dns\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ZoneShowTest extends TestCase
{
    use RefreshDatabase;

    private DnsZone $zone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        Queue::fake();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->with('system.addresses')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => []]);
        $this->app->instance(AgentClient::class, $agent);
        $this->zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr']);
    }

    private function show()
    {
        return Livewire::test(ZoneShow::class, ['zone' => $this->zone->id]);
    }

    /** @return list<string> */
    private function records(): array
    {
        return $this->zone->records()->get()
            ->map(fn ($r) => trim("{$r->name} {$r->type} ".($r->priority !== null ? $r->priority.' ' : '')."{$r->content} {$r->ttl}"))
            ->all();
    }

    public function test_detail_page_lists_records(): void
    {
        $this->get('/dns/'.$this->zone->id)
            ->assertOk()
            ->assertSee('kahve.com.tr')
            ->assertSee('ns1.example.net.')
            ->assertSee('203.0.113.10')
            ->assertSee('4 / 4 kayıt');
    }

    public function test_adds_a_record(): void
    {
        $this->show()
            ->call('openAdd')
            ->assertSet('editingRecord', true)
            ->set('recordType', 'MX')
            ->set('recordName', '')
            ->set('recordValue', 'mail')
            ->set('recordPriority', '20')
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->assertSet('editingRecord', false)
            ->assertDispatched('toast');

        $this->assertContains('@ MX 20 mail.kahve.com.tr. 3600', $this->records());
        $this->assertSame(ZoneStatus::Syncing, $this->zone->fresh()->status);
        Queue::assertPushed(SyncDnsZone::class);
    }

    public function test_rejects_invalid_and_conflicting_records(): void
    {
        $this->show()
            ->call('openAdd')
            ->set('recordType', 'A')
            ->set('recordValue', '999.1.1.1')
            ->call('saveRecord')
            ->assertHasErrors('recordValue')
            ->set('recordValue', '203.0.113.10')
            ->call('saveRecord')
            ->assertHasErrors('recordValue') // duplicate
            ->set('recordName', 'www')
            ->set('recordValue', '203.0.113.11')
            ->call('saveRecord')
            ->assertHasErrors('recordName') // www has a CNAME
            ->set('recordType', 'CNAME')
            ->set('recordName', '')
            ->set('recordValue', 'baska.com')
            ->call('saveRecord')
            ->assertHasErrors('recordName'); // CNAME at the apex

        Queue::assertNothingPushed();
    }

    public function test_records_of_one_name_and_type_share_a_ttl(): void
    {
        $this->show()
            ->call('openAdd')
            ->set('recordType', 'A')
            ->set('recordValue', '203.0.113.11')
            ->set('recordTtl', 300)
            ->call('saveRecord')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => str_contains($p['message'], "1 kaydın TTL'si de değişti"));

        $this->assertContains('@ A 203.0.113.10 300', $this->records());
        $this->assertContains('@ A 203.0.113.11 300', $this->records());
    }

    public function test_edits_a_record_without_changing_its_type(): void
    {
        $a = $this->zone->records()->where('type', 'A')->sole();

        $this->show()
            ->call('openEdit', $a->id)
            ->assertSet('recordValue', '203.0.113.10')
            ->set('recordType', 'TXT')
            ->set('recordValue', '203.0.113.99')
            ->call('saveRecord')
            ->assertHasNoErrors();

        $this->assertSame(['A', '203.0.113.99'], [$a->fresh()->type, $a->fresh()->content]);
    }

    public function test_deletes_records_and_can_undo(): void
    {
        $www = $this->zone->records()->where('name', 'www')->sole();

        $component = $this->show()
            ->set('selected', [$www->id])
            ->call('deleteSelected')
            ->assertDispatched('toast');
        $this->assertCount(3, $this->records());

        $token = $component->effects['dispatches'][0]['params']['undo']['args'][0];
        $component->call('undo', $token);

        $this->assertContains('www CNAME kahve.com.tr. 3600', $this->records());
        $this->assertCount(4, $this->records());
    }

    public function test_keeps_at_least_one_name_server(): void
    {
        $ns = $this->zone->records()->where('type', 'NS')->pluck('id')->all();

        $this->show()
            ->set('selected', $ns)
            ->call('deleteSelected')
            ->assertDispatched('toast', fn ($n, $p) => str_contains($p['message'], 'en az bir NS'));

        $this->assertCount(4, $this->records());
    }

    public function test_reset_returns_to_the_template(): void
    {
        $this->zone->records()->create(['name' => 'eski', 'type' => 'A', 'content' => '104.21.8.17', 'ttl' => 3600]);

        $this->show()->call('resetToTemplate')->assertDispatched('toast');

        $records = $this->records();
        $this->assertNotContains('eski A 104.21.8.17 3600', $records);
        $this->assertContains('@ NS ns1.kahve.com.tr. 3600', $records);
        $this->assertContains('@ A 203.0.113.10 3600', $records);
    }

    public function test_saves_zone_settings(): void
    {
        $this->show()
            ->set('tab', 'settings')
            ->assertSee('Gelişmiş (SOA)')
            ->set('defaultTtl', 300)
            ->set('hostmaster', 'dns@kahve.com.tr')
            ->set('refresh', 7200)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->set('minimum', 5)
            ->call('saveSettings')
            ->assertHasErrors('minimum');

        $zone = $this->zone->fresh();
        $this->assertSame([300, 'dns@kahve.com.tr', 7200], [$zone->default_ttl, $zone->hostmaster, $zone->refresh]);
        Queue::assertPushed(SyncDnsZone::class);
    }

    public function test_dnssec_shows_the_ds_record_and_asks_before_turning_off(): void
    {
        $page = $this->show()
            ->set('tab', 'settings')
            ->assertSee('Bölgeyi imzala')
            ->assertDontSee('Kayıt firmasına eklenecek DS kaydı')
            ->set('dnssec', true)
            ->call('saveSettings')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($name, $p) => str_contains($p['message'], 'DNSSEC açılıyor') && $p['undo']['method'] === 'undo')
            ->assertSee('Anahtar oluşturuluyor');
        $this->assertTrue($this->zone->fresh()->dnssec);
        $this->assertSame(['enabled' => true], $this->zone->fresh()->agentSpec()['dnssec']);
        Queue::assertPushed(SyncDnsZone::class);

        // The agent signed it and sent the key back.
        $this->zone->update([
            'status' => ZoneStatus::Active,
            'dnssec_key' => "Private-key-format: v1.2\nAlgorithm: 13 (ECDSAP256SHA256)\nPrivateKey: c2VjcmV0\n",
            'dnssec_info' => ['key_tag' => 34249, 'algorithm' => 13, 'ds' => ['34249 13 2 0808c5f8226ad554bf9ec260dd1600fca0d099149a40f4ebaef69cb3e3155d69', '34249 13 4 8d1e87'], 'dnskey' => '257 3 13 u6J0IISi7zOFewuOTb4OV9Dtjk=='],
        ]);
        $page->call('$refresh')
            ->assertSee('Kayıt firmasına eklenecek DS kaydı')
            ->assertSee('34249')
            ->assertSee('0808C5F8226AD554BF9EC260DD1600FCA0D099149A40F4EBAEF69CB3E3155D69')
            ->assertSee('u6J0IISi7zOFewuOTb4OV9Dtjk==')
            ->assertDontSee('c2VjcmV0');
        $this->assertSame('kahve.com.tr. IN DS 34249 13 2 0808c5f8226ad554bf9ec260dd1600fca0d099149a40f4ebaef69cb3e3155d69', $this->zone->fresh()->dsRecord()['record']);
        $this->assertStringContainsString('PrivateKey: c2VjcmV0', $this->zone->fresh()->agentSpec()['dnssec']['key']);
        $this->assertArrayNotHasKey('dnssec_key', $this->zone->fresh()->toArray());

        // Off only after confirming; the key stays for later.
        $page->set('dnssec', false)
            ->call('saveSettings')
            ->assertSet('confirmingDnssecOff', true)
            ->call('keepDnssec')
            ->assertSet('dnssec', true);
        $this->assertTrue($this->zone->fresh()->dnssec);
        $page->set('dnssec', false)
            ->call('saveSettings')
            ->call('confirmDnssecOff')
            ->assertSet('confirmingDnssecOff', false)
            ->assertDispatched('toast', fn ($name, $p) => str_contains($p['message'], 'DNSSEC kapatıldı'));
        $zone = $this->zone->fresh();
        $this->assertFalse($zone->dnssec);
        $this->assertNull($zone->dsRecord());
        $this->assertSame(false, $zone->agentSpec()['dnssec']['enabled']);
        $this->assertNotEmpty($zone->agentSpec()['dnssec']['key']);
    }

    public function test_undo_brings_dnssec_and_its_key_back_to_a_deleted_zone(): void
    {
        $this->zone->update(['dnssec' => true, 'dnssec_key' => 'Private-key-format: v1.2']);
        $zones = app(Zones::class);
        $token = $zones->delete($this->zone)[$this->zone->id];
        $this->zone->delete();

        $restored = $zones->undo($token);
        $this->assertTrue($restored->dnssec);
        $this->assertSame('Private-key-format: v1.2', $restored->fresh()->dnssec_key);
    }

    public function test_exports_a_zone_file(): void
    {
        $this->show()
            ->set('tab', 'settings')
            ->call('export')
            ->assertFileDownloaded('kahve.com.tr.zone');

        $file = $this->zone->fresh()->zoneFile();
        $this->assertStringContainsString('$ORIGIN kahve.com.tr.', $file);
        $this->assertStringContainsString('@ 3600 IN SOA ns1.example.net. hostmaster.kahve.com.tr. 2026101801 10800 3600 604800 3600', $file);
        $this->assertStringContainsString('www 3600 IN CNAME kahve.com.tr.', $file);
    }

    public function test_failed_zone_can_be_retried(): void
    {
        $this->zone->update(['status' => ZoneStatus::Failed, 'last_error' => 'PowerDNS API unreachable']);

        $this->show()
            ->assertSee('PowerDNS API unreachable')
            ->call('retry');

        $this->assertSame(ZoneStatus::Syncing, $this->zone->fresh()->status);
        Queue::assertPushed(SyncDnsZone::class);
    }

    public function test_returns_to_the_list_once_the_zone_is_gone(): void
    {
        $component = $this->show();
        $this->zone->delete();

        $component->call('refreshStatus')->assertRedirect(route('dns.index'));
    }
}
