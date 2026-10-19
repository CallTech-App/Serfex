<?php

namespace Database\Factories;

use App\Models\DatabaseUser;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseUser>
 */
class DatabaseUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server' => 'mysql',
            'name' => 'u'.fake()->unique()->numerify('#####').'_app',
            'system_user_id' => SystemUser::factory(),
            'hosts' => [],
        ];
    }
}
