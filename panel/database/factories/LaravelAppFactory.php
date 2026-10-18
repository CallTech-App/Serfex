<?php

namespace Database\Factories;

use App\Enums\LaravelAppStatus;
use App\Models\LaravelApp;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LaravelApp>
 */
class LaravelAppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'path' => LaravelApp::MANAGED_PATH,
            'status' => LaravelAppStatus::Active,
            'source' => 'git',
            'repository' => 'https://github.com/hizlikargo/api.git',
            'branch' => 'main',
            'laravel_version' => '13.6.0',
            'php_requirement' => '^8.3',
            'current_release' => '1',
            'installed_at' => now(),
        ];
    }
}
