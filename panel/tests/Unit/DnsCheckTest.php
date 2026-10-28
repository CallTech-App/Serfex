<?php

namespace Tests\Unit;

use App\Services\Agent\AgentClient;
use App\Services\Dns\DnsSettings;
use App\Services\Ssl\DnsCheck;
use Tests\TestCase;

class DnsCheckTest extends TestCase
{
    /**
     * @param  list<string>  $found
     */
    private function check(array $found): DnsCheck
    {
        return new class($found, app(AgentClient::class), app(DnsSettings::class)) extends DnsCheck
        {
            /** @param list<string> $found */
            public function __construct(private array $found, AgentClient $agent, DnsSettings $settings)
            {
                parent::__construct($agent, $settings);
            }

            public function lookup(string $name): array
            {
                return $this->found;
            }

            public function serverAddresses(): array
            {
                return ['192.0.2.10'];
            }
        };
    }

    public function test_name_pointing_here_is_ok(): void
    {
        $this->assertSame(['ok' => true, 'found' => ['192.0.2.10'], 'proxied' => false], $this->check(['192.0.2.10'])->check('serfex.ai'));
    }

    public function test_name_behind_cloudflare_counts_as_pointing_here(): void
    {
        $result = $this->check(['172.67.143.102', '104.21.39.52', '2606:4700:3030::6815:2734'])->check('serfex.ai');

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['proxied']);
    }

    public function test_other_server_is_not_ok(): void
    {
        $this->assertFalse($this->check(['198.51.100.7'])->check('serfex.ai')['ok']);
        // Partly elsewhere: Let's Encrypt may ask the other address.
        $this->assertFalse($this->check(['104.21.39.52', '198.51.100.7'])->check('serfex.ai')['ok']);
        $this->assertFalse($this->check([])->check('serfex.ai')['ok']);
    }

    public function test_cloudflare_ranges(): void
    {
        foreach (['173.245.48.1', '104.16.0.0', '104.23.255.255', '172.71.255.255', '131.0.75.255', '2400:cb00::1', '2a06:98c7:ffff::1'] as $ip) {
            $this->assertTrue(DnsCheck::isCloudflare($ip), $ip);
        }
        foreach (['104.15.255.255', '172.72.0.0', '131.0.76.0', '192.0.2.10', '2a06:98c8::1', '2001:db8::1', 'nonsense'] as $ip) {
            $this->assertFalse(DnsCheck::isCloudflare($ip), $ip);
        }
    }
}
