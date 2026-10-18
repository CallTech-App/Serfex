<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_runs_on_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());

        User::factory()->create(['email' => 'admin@example.test']);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.test']);
    }
}
