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
}
