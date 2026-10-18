<?php

namespace Database\Factories;

use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemUser>
 */
class SystemUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'u'.fake()->unique()->numerify('#####'),
        ];
    }
}
