<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'package_id' => fn () => Package::default()->id,
            'company' => fake()->company(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'username' => 'c'.fake()->unique()->numerify('######'),
        ];
    }

    /**
     * With a panel login that has set its password ("password").
     */
    public function withLogin(): static
    {
        return $this->afterCreating(fn (Customer $customer) => User::forceCreate([
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'password' => Hash::make('password'),
            'is_admin' => false,
        ]));
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now()]);
    }
}
