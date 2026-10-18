<?php

namespace Tests\Integration;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a real servik-agent binary in dry-run mode, so site changes land
 * in a scratch directory instead of the machine. Set SERVIK_AGENT_BIN to its
 * path (CI builds it from ../agent); the test is skipped otherwise.
 */
class AgentIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private string $socket;

    private string $root;

    protected function setUp(): void
    {
        $bin = getenv('SERVIK_AGENT_BIN');
        if (! $bin || ! is_executable($bin)) {
            $this->markTestSkipped('SERVIK_AGENT_BIN is not set.');
        }

        $dir = sys_get_temp_dir().'/servik-agent-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->socket = $dir.'/agent.sock';
        $this->root = $dir.'/root';
        $this->process = proc_open(
            [$bin, '-socket', $this->socket, '-dry-run', '-root', $this->root],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 50 && ! file_exists($this->socket); $i++) {
            usleep(100_000);
        }
        $this->assertFileExists($this->socket, 'agent did not create its socket');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if (isset($this->root) && is_dir(dirname($this->root))) {
            exec('rm -rf '.escapeshellarg(dirname($this->root)));
        }
    }

    public function test_ping_and_info(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertTrue($client->ping());
        $this->assertSame('linux', $client->call('agent.info')['os']);
    }

    public function test_unknown_method_is_reported(): void
    {
        $client = new AgentClient($this->socket);

        $this->expectException(AgentException::class);
        $this->expectExceptionCode(-32601);
        $client->call('does.not.exist');
    }

    public function test_site_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertSame(['8.3', '8.4', '8.5'], $client->call('php.versions'));

        $spec = ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahveatolyesi.com.tr']];
        $result = $client->call('site.apply', $spec, 30);

        $this->assertTrue($result['user_created']);
        $this->assertSame('/home/kahve/web/kahveatolyesi.com.tr/public_html', $result['document_root']);
        // The link targets the real /etc path, so inside the scratch root it dangles.
        $this->assertSame('/etc/nginx/sites-available/kahveatolyesi.com.tr.conf', readlink($this->root.'/etc/nginx/sites-enabled/kahveatolyesi.com.tr.conf'));
        $this->assertStringContainsString(
            'server_name kahveatolyesi.com.tr www.kahveatolyesi.com.tr;',
            file_get_contents($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf'),
        );
        $this->assertFileExists($this->root.'/etc/php/8.4/fpm/pool.d/kahveatolyesi.com.tr.conf');

        $this->assertFalse($client->call('site.apply', $spec, 30)['changed'], 'apply must be idempotent');

        $deleted = $client->call('site.delete', ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'remove_files' => true, 'remove_user' => true], 30);
        $this->assertTrue($deleted['user_removed']);
        $this->assertFileDoesNotExist($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf');
        $this->assertDirectoryDoesNotExist($this->root.'/home/kahve');
    }

    public function test_certificate_lifecycle(): void
    {
        $client = new AgentClient($this->socket);
        $spec = ['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => ['www.kahveatolyesi.com.tr']];
        $client->call('site.apply', $spec, 30);

        // Dry runs use a simulated CA but the same challenge plumbing.
        $issued = $client->call('ssl.issue', ['name' => 'kahveatolyesi.com.tr', 'names' => ['kahveatolyesi.com.tr', 'www.kahveatolyesi.com.tr'], 'challenge' => 'http-01'], 30);
        $this->assertSame(['kahveatolyesi.com.tr', 'www.kahveatolyesi.com.tr'], $issued['names']);
        $this->assertGreaterThan(time(), strtotime($issued['not_after']));
        $this->assertFileExists($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/privkey.pem')), -4));

        $client->call('site.apply', [...$spec, 'tls' => ['certificate' => 'kahveatolyesi.com.tr', 'redirect' => true]], 30);
        $vhost = file_get_contents($this->root.'/etc/nginx/sites-available/kahveatolyesi.com.tr.conf');
        $this->assertStringContainsString('listen 443 ssl', $vhost);
        $this->assertStringContainsString('ssl_certificate /etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem;', $vhost);
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $vhost);
        $this->assertStringContainsString('/.well-known/acme-challenge/', $vhost);

        $this->assertSame($issued['serial'], $client->call('ssl.get', ['name' => 'kahveatolyesi.com.tr'])['serial']);
        try {
            $client->call('ssl.delete', ['name' => 'kahveatolyesi.com.tr']);
            $this->fail('a certificate in use must not be deleted');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
        }

        $client->call('site.apply', $spec, 30);
        $this->assertTrue($client->call('ssl.delete', ['name' => 'kahveatolyesi.com.tr'])['deleted']);
        $this->assertFileDoesNotExist($this->root.'/etc/servik/ssl/certs/kahveatolyesi.com.tr/fullchain.pem');
    }

    public function test_invalid_site_is_rejected_with_a_reason(): void
    {
        $client = new AgentClient($this->socket);

        try {
            $client->call('site.apply', ['domain' => 'localhost', 'user' => 'kahve', 'php_version' => '8.4', 'aliases' => []]);
            $this->fail('expected an AgentException');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
            $this->assertStringContainsString('at least two labels', $e->reason);
        }
    }

    public function test_dns_lifecycle(): void
    {
        $client = new AgentClient($this->socket);

        $this->assertFalse($client->call('dns.status')['configured']);
        try {
            $client->call('dns.zone.get', ['zone' => 'kahveatolyesi.com.tr']);
            $this->fail('expected the DNS server to be missing');
        } catch (AgentException $e) {
            $this->assertSame(-32001, $e->getCode());
        }

        $setup = $client->call('dns.setup', [], 30);
        $this->assertTrue($setup['installed']);
        $this->assertSame(['installed' => true, 'configured' => true, 'running' => true, 'version' => 'simulated'], $client->call('dns.status'));
        $this->assertStringContainsString('launch=lmdb', file_get_contents($this->root.'/etc/powerdns/pdns-servik.conf'));
        $this->assertSame(['ipv4' => ['203.0.113.10'], 'ipv6' => ['2001:db8::10']], $client->call('system.addresses'));

        $spec = [
            'zone' => 'kahveatolyesi.com.tr',
            'soa' => ['primary' => 'ns1.kahveatolyesi.com.tr.', 'hostmaster' => 'hostmaster@kahveatolyesi.com.tr', 'refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600, 'ttl' => 3600],
            'records' => [
                ['name' => '@', 'type' => 'NS', 'ttl' => 3600, 'content' => 'ns1.kahveatolyesi.com.tr.'],
                ['name' => 'ns1', 'type' => 'A', 'ttl' => 3600, 'content' => '203.0.113.10'],
                ['name' => '@', 'type' => 'MX', 'ttl' => 3600, 'content' => '10 mail.kahveatolyesi.com.tr.'],
                ['name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'content' => 'v=spf1 a mx ~all'],
            ],
        ];
        $applied = $client->call('dns.zone.apply', $spec, 30);
        $this->assertTrue($applied['created']);
        $this->assertFalse($client->call('dns.zone.apply', $spec, 30)['changed'], 'apply must be idempotent');

        $zone = $client->call('dns.zone.get', ['zone' => 'kahveatolyesi.com.tr']);
        $this->assertSame($applied['serial'], $zone['serial']);
        $this->assertContains(['name' => '@', 'type' => 'TXT', 'ttl' => 3600, 'content' => 'v=spf1 a mx ~all'], $zone['records']);

        try {
            $client->call('dns.zone.apply', [...$spec, 'records' => [...$spec['records'], ['name' => '@', 'type' => 'CNAME', 'ttl' => 3600, 'content' => 'x.example.com.']]], 30);
            $this->fail('expected an apex CNAME to be rejected');
        } catch (AgentException $e) {
            $this->assertSame(AgentException::INVALID_PARAMS, $e->getCode());
            $this->assertStringContainsString('CNAME', $e->reason);
        }

        $this->assertTrue($client->call('dns.zone.delete', ['zone' => 'kahveatolyesi.com.tr'], 30)['deleted']);
    }
}
