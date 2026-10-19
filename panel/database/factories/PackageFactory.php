<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Paket '.fake()->unique()->numerify('####'),
            'max_sites' => 3,
            'max_disk_mb' => 1024,
            'max_traffic_gb' => 100,
            'max_mailboxes' => 5,
            'max_databases' => 5,
            'max_laravel_apps' => 1,
            'allow_laravel' => true,
            'allow_shell' => false,
            'allow_mail' => true,
            'allow_dns' => true,
        ];
    }
}
