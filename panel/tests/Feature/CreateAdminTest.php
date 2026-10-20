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
        $this->artisan('servik:admin', ['--email' => 'sercan@example.test', '--name' => 'Sercan', '--password' => 'correct-horse-battery'])
            ->assertSuccessful();

        $admin = User::sole();
        $this->assertSame('sercan@example.test', $admin->email);
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));
    }

    public function test_resets_the_existing_admin_instead_of_adding_one(): void
    {
        User::factory()->create(['email' => 'old@example.test']);

        $this->artisan('servik:admin', ['--email' => 'new@example.test', '--name' => 'Sercan', '--password' => 'another-long-password'])
            ->assertSuccessful();

        $this->assertSame('new@example.test', User::sole()->email);
    }

    public function test_a_customer_login_is_never_taken_over(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $login = $customer->user->email;

        $this->artisan('servik:admin', ['--email' => 'sercan@example.test', '--name' => 'Sercan', '--password' => 'another-long-password'])
            ->assertSuccessful();

        $this->assertSame($login, $customer->user->fresh()->email);
        $this->assertTrue(User::where('email', 'sercan@example.test')->sole()->is_admin);
    }

    public function test_rejects_short_passwords(): void
    {
        $this->artisan('servik:admin', ['--email' => 'sercan@example.test', '--name' => 'Sercan', '--password' => 'short'])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
