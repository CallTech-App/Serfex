<?php

namespace Database\Factories;

use App\Models\Database;
use App\Models\Site;
use App\Models\SystemUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Database>
 */
class DatabaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'server' => 'mysql',
            'name' => 'u'.fake()->unique()->numerify('#####').'_shop',
            'system_user_id' => SystemUser::factory(),
            'site_id' => null,
            'size_bytes' => 0,
        ];
    }

    /**
     * On $site, named after its account.
     */
    public function forSite(Site $site, string $suffix = 'shop'): static
    {
        return $this->state([
            'site_id' => $site->id,
            'system_user_id' => $site->system_user_id,
            'name' => str_replace('-', '_', $site->systemUser->name).'_'.$suffix,
        ]);
    }
}
