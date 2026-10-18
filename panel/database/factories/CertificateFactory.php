<?php

namespace Database\Factories;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->unique()->domainWord().'.com.tr',
            'source' => Certificate::LETS_ENCRYPT,
            'domains' => [],
            'challenge' => 'http-01',
            'issuer' => "Let's Encrypt",
            'not_before' => now()->subDays(10),
            'not_after' => now()->addDays(80),
            'status' => CertificateStatus::Active,
            'issued_at' => now()->subDays(10),
        ];
    }

    /**
     * The certificate of $site, covering its domain and aliases, in use.
     */
    public function inUseBy(Site $site): static
    {
        return $this->state(['site_id' => $site->id, 'name' => $site->domain, 'domains' => [$site->domain, ...($site->aliases ?? [])]])
            ->afterCreating(fn (Certificate $c) => $site->update(['certificate_id' => $c->id]));
    }

    public function expiring(int $days): static
    {
        return $this->state(['not_after' => now()->addDays($days)->addHour()]);
    }
}
