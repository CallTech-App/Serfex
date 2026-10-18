<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_when_agent_is_down(): void
    {
        config(['servik.agent.socket' => sys_get_temp_dir().'/servik-missing.sock']);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Genel Bakış')
            ->assertSee('Sunucu ajanına ulaşılamıyor');
    }
}
