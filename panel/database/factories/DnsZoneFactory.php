<?php

namespace Database\Factories;

use App\Enums\ZoneStatus;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsZone>
 */
class DnsZoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'.com.tr',
            'status' => ZoneStatus::Active,
            'default_ttl' => 3600,
            'refresh' => 10800,
            'retry' => 3600,
            'expire' => 604800,
            'minimum' => 3600,
            'serial' => 2026101801,
        ];
    }

    /**
     * Adds NS records and an apex A record.
     */
    public function withRecords(): static
    {
        return $this->afterCreating(function (DnsZone $zone) {
            $zone->records()->createMany([
                ['name' => '@', 'type' => 'NS', 'content' => 'ns1.serfex.net.tr.', 'ttl' => 3600],
                ['name' => '@', 'type' => 'NS', 'content' => 'ns2.serfex.net.tr.', 'ttl' => 3600],
                ['name' => '@', 'type' => 'A', 'content' => '203.0.113.10', 'ttl' => 3600],
                ['name' => 'www', 'type' => 'CNAME', 'content' => $zone->name.'.', 'ttl' => 3600],
            ]);
        });
    }
}
