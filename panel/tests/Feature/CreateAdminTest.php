<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_the_admin(): void
    {
        $this->artisan('serfex:admin', ['--email' => 'aylin@example.test', '--name' => 'Aylin', '--password' => 'correct-horse-battery'])
            ->assertSuccessful();

        $admin = User::sole();
        $this->assertSame('aylin@example.test', $admin->email);
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));
    }

    public function test_resets_the_existing_admin_instead_of_adding_one(): void
    {
        User::factory()->create(['email' => 'old@example.test']);

        $this->artisan('serfex:admin', ['--email' => 'new@example.test', '--name' => 'Aylin', '--password' => 'another-long-password'])
            ->assertSuccessful();

        $this->assertSame('new@example.test', User::sole()->email);
    }

    public function test_a_customer_login_is_never_taken_over(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $login = $customer->user->email;

        $this->artisan('serfex:admin', ['--email' => 'aylin@example.test', '--name' => 'Aylin', '--password' => 'another-long-password'])
            ->assertSuccessful();

        $this->assertSame($login, $customer->user->fresh()->email);
        $this->assertTrue(User::where('email', 'aylin@example.test')->sole()->is_admin);
    }

    public function test_rejects_short_passwords(): void
    {
        $this->artisan('serfex:admin', ['--email' => 'aylin@example.test', '--name' => 'Aylin', '--password' => 'short'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
