<?php

namespace Database\Factories;

use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'system_user_id' => SystemUser::factory(),
            'domain' => fake()->unique()->domainWord().'.com.tr',
            'aliases' => [],
            'php_version' => '8.4',
            'status' => SiteStatus::Active,
        ];
    }

    public function provisioning(): static
    {
        return $this->state(['status' => SiteStatus::Provisioning]);
    }
}
