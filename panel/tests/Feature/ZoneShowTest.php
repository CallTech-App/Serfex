<?php

namespace Tests\Feature;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Livewire\Dns\ZoneShow;
use App\Models\DnsZone;
use App\Models\User;
use App\Services\Agent\AgentClient;
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
            ->assertSee('ns1.serfex.net.tr.')
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
            ->set('tab', 'ayarlar')
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

    public function test_exports_a_zone_file(): void
    {
        $this->show()
            ->set('tab', 'ayarlar')
            ->call('export')
            ->assertFileDownloaded('kahve.com.tr.zone');

        $file = $this->zone->fresh()->zoneFile();
        $this->assertStringContainsString('$ORIGIN kahve.com.tr.', $file);
        $this->assertStringContainsString('@ 3600 IN SOA ns1.serfex.net.tr. hostmaster.kahve.com.tr. 2026101801 10800 3600 604800 3600', $file);
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
