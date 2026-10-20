<?php

namespace Tests\Unit;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Tests\TestCase;

class AgentClientTest extends TestCase
{
    public function test_unreachable_socket_throws(): void
    {
        $client = new AgentClient(sys_get_temp_dir().'/servik-missing.sock', 0.5);

        $this->expectException(AgentException::class);
        $client->call('system.ping');
    }

    public function test_ping_returns_false_when_unreachable(): void
    {
        $client = new AgentClient(sys_get_temp_dir().'/servik-missing.sock', 0.5);

        $this->assertFalse($client->ping());
    }
}
