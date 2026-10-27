<?php

namespace Tests\Feature;

use App\Enums\ZoneStatus;
use App\Jobs\DeleteDnsZone;
use App\Jobs\SetupDnsServer;
use App\Jobs\SyncDnsZone;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DnsJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_sends_the_whole_zone_and_records_the_serial(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr', 'status' => ZoneStatus::Syncing, 'serial' => null]);
        $zone->records()->create(['name' => '@', 'type' => 'MX', 'content' => 'mail.kahve.com.tr.', 'priority' => 10, 'ttl' => 3600]);
        $zone->records()->create(['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 a mx ~all', 'ttl' => 3600]);

        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('dns.zone.apply', Mockery::on(function ($spec) {
            $this->assertSame('kahve.com.tr', $spec['zone']);
            $this->assertSame(['primary' => 'ns1.serfex.net.tr.', 'hostmaster' => 'hostmaster@kahve.com.tr', 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600, 'ttl' => 3600], $spec['soa']);
            $this->assertContains(['name' => '@', 'type' => 'MX', 'ttl' => 3600, 'content' => '10 mail.kahve.com.tr.'], $spec['records']);
            $this->assertContains(['name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'content' => 'v=spf1 a mx ~all'], $spec['records']);
            $this->assertCount(6, $spec['records']);

            return true;
        }), 120.0)->andReturn(['zone' => 'kahve.com.tr', 'serial' => 2026101803, 'created' => true, 'changed' => true]);

        (new SyncDnsZone($zone))->handle($agent);

        $zone->refresh();
        $this->assertSame(ZoneStatus::Active, $zone->status);
        $this->assertSame(2026101803, $zone->serial);
        $this->assertNotNull($zone->synced_at);
    }

    public function test_sync_failure_is_shown_on_the_zone(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['status' => ZoneStatus::Syncing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('dns.zone.apply', -32602, 'the DNS server rejected the zone: Conflicts with pre-existing RRset'));

        (new SyncDnsZone($zone))->handle($agent);

        $zone->refresh();
        $this->assertSame(ZoneStatus::Failed, $zone->status);
        $this->assertStringContainsString('Conflicts', $zone->last_error);
    }

    public function test_sync_explains_a_missing_dns_server(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['status' => ZoneStatus::Syncing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('dns.zone.apply', SyncDnsZone::NOT_SET_UP, 'the DNS server is not set up yet'));

        (new SyncDnsZone($zone))->handle($agent);

        $this->assertStringContainsString('DNS sunucusu henüz kurulmadı', $zone->fresh()->last_error);
    }

    public function test_delete_removes_the_zone_from_the_agent_and_the_panel(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'kahve.com.tr', 'status' => ZoneStatus::Deleting]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('dns.zone.delete', ['zone' => 'kahve.com.tr'], 120.0)->andReturn(['deleted' => true]);

        (new DeleteDnsZone($zone))->handle($agent);

        $this->assertSame(0, DnsZone::count());
    }

    public function test_delete_skips_a_zone_whose_deletion_was_undone(): void
    {
        $zone = DnsZone::factory()->create(['status' => ZoneStatus::Syncing]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldNotReceive('call');

        (new DeleteDnsZone($zone))->handle($agent);

        $this->assertTrue($zone->exists());
    }

    public function test_delete_failure_keeps_the_zone(): void
    {
        $zone = DnsZone::factory()->create(['status' => ZoneStatus::Deleting]);
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::unavailable('/run/serfex-agent/agent.sock', 'Connection refused'));

        (new DeleteDnsZone($zone))->handle($agent);

        $this->assertSame(ZoneStatus::Failed, $zone->fresh()->status);
    }

    public function test_setup_installs_powerdns_and_publishes_waiting_zones(): void
    {
        Queue::fake();
        $failed = DnsZone::factory()->create(['status' => ZoneStatus::Failed, 'last_error' => 'not set up']);
        $active = DnsZone::factory()->create();
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('dns.setup', [], 600)->andReturn(['installed' => true, 'changed' => true, 'version' => '4.8.3']);

        (new SetupDnsServer)->handle($agent);

        $this->assertSame(['state' => 'ready', 'version' => '4.8.3'], array_intersect_key(Setting::get('dns.server'), ['state' => 1, 'version' => 1]));
        $this->assertSame(ZoneStatus::Syncing, $failed->fresh()->status);
        Queue::assertPushed(SyncDnsZone::class, fn ($job) => $job->zone->is($failed));
        Queue::assertNotPushed(SyncDnsZone::class, fn ($job) => $job->zone->is($active));
    }

    public function test_setup_failure_is_remembered(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->andThrow(AgentException::rpcError('dns.setup', -32000, 'PowerDNS did not start'));

        (new SetupDnsServer)->handle($agent);

        $this->assertSame('failed', Setting::get('dns.server')['state']);
        $this->assertSame('PowerDNS did not start', Setting::get('dns.server')['error']);
    }
}
