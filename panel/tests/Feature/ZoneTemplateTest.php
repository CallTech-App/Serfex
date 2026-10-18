<?php

namespace Tests\Feature;

use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\ZoneTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ZoneTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function records(string $zone, bool $empty = false): array
    {
        return collect(app(ZoneTemplate::class)->records($zone, $empty))
            ->map(fn ($r) => trim("{$r['name']} {$r['type']} ".($r['priority'] !== null ? $r['priority'].' ' : '')."{$r['content']} {$r['ttl']}"))
            ->all();
    }

    public function test_detects_addresses_and_uses_per_domain_name_servers_by_default(): void
    {
        $agent = Mockery::mock(AgentClient::class);
        $agent->shouldReceive('call')->once()->with('system.addresses')->andReturn(['ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::10']]);
        $this->app->instance(AgentClient::class, $agent);

        $this->assertSame([
            '@ NS ns1.kahve.com.tr. 3600',
            '@ NS ns2.kahve.com.tr. 3600',
            'ns1 A 203.0.113.10 3600',
            'ns1 AAAA 2001:db8::10 3600',
            'ns2 A 203.0.113.10 3600',
            'ns2 AAAA 2001:db8::10 3600',
            '@ A 203.0.113.10 3600',
            '@ AAAA 2001:db8::10 3600',
            'www CNAME kahve.com.tr. 3600',
        ], $this->records('kahve.com.tr'));
        $this->assertSame('203.0.113.10', app(DnsSettings::class)->ipv4());
    }

    public function test_server_name_servers_ttl_and_custom_rows(): void
    {
        app(DnsSettings::class)->save([
            'nameservers' => ['ns1.servik.net.tr', 'ns2.servik.net.tr'],
            'ipv4' => '203.0.113.10',
            'ipv6' => null,
            'default_ttl' => 300,
            'template' => [
                ['name' => '@', 'type' => 'A', 'content' => '<ip4>', 'priority' => null, 'ttl' => null],
                ['name' => '@', 'type' => 'AAAA', 'content' => '<ip6>', 'priority' => null, 'ttl' => null],
                ['name' => 'mail', 'type' => 'A', 'content' => '<ip4>', 'priority' => null, 'ttl' => 86400],
                ['name' => '@', 'type' => 'MX', 'content' => 'mail.<domain>.', 'priority' => 10, 'ttl' => null],
                ['name' => '@', 'type' => 'TXT', 'content' => 'v=spf1 a mx ~all', 'priority' => null, 'ttl' => null],
            ],
        ]);

        $this->assertSame([
            '@ NS ns1.servik.net.tr. 300',
            '@ NS ns2.servik.net.tr. 300',
            '@ A 203.0.113.10 300',
            'mail A 203.0.113.10 86400',
            '@ MX 10 mail.kahve.com.tr. 300',
            '@ TXT v=spf1 a mx ~all 300',
        ], $this->records('kahve.com.tr'));

        $this->assertSame(['@ NS ns1.servik.net.tr. 300', '@ NS ns2.servik.net.tr. 300'], $this->records('kahve.com.tr', empty: true));
    }

    public function test_glue_records_for_name_servers_inside_the_zone(): void
    {
        app(DnsSettings::class)->save(['nameservers' => ['ns1.servik.net.tr', 'ns2.servik.net.tr'], 'ipv4' => '203.0.113.10']);

        $records = $this->records('servik.net.tr', empty: true);

        $this->assertContains('ns1 A 203.0.113.10 3600', $records);
        $this->assertContains('ns2 A 203.0.113.10 3600', $records);
    }
}
