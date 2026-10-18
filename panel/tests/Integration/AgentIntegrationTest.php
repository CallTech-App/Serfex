<?php

namespace Tests\Integration;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use PHPUnit\Framework\TestCase;

/**
 * Talks to a real servik-agent binary. Set SERVIK_AGENT_BIN to its path
 * (CI builds it from ../agent); the test is skipped otherwise.
 */
class AgentIntegrationTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    private string $socket;

    protected function setUp(): void
    {
        $bin = getenv('SERVIK_AGENT_BIN');
        if (! $bin || ! is_executable($bin)) {
            $this->markTestSkipped('SERVIK_AGENT_BIN is not set.');
        }

        $this->socket = sys_get_temp_dir().'/servik-agent-'.getmypid().'/agent.sock';
        $this->process = proc_open(
            [$bin, '-socket', $this->socket],
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
}
