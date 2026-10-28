<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_pages_require_login(): void
    {
        foreach (['/', '/sites'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/login')->assertOk()->assertSee('Panele giriş yapın');
    }

    public function test_admin_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'aylin@example.test', 'password' => 'correct-horse-battery']);

        // The first sign-in to a new panel opens the setup wizard.
        $this->post('/login', ['email' => 'aylin@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/install');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['email' => 'aylin@example.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/');
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'aylin@example.test', 'password' => 'correct-horse-battery']);

        $this->from('/login')
            ->post('/login', ['email' => 'aylin@example.test', 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'E-posta ya da parola hatalı.']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'aylin@example.test']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'aylin@example.test', 'password' => 'nope']);
        }

        $this->post('/login', ['email' => 'aylin@example.test', 'password' => 'nope'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Çok fazla deneme', session('errors')->first('email'));
    }
}
