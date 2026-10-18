<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_renders_when_agent_is_down(): void
    {
        config(['servik.agent.socket' => sys_get_temp_dir().'/servik-missing.sock']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Servik')
            ->assertSee('Sunucu ajanına ulaşılamıyor');
    }
}
