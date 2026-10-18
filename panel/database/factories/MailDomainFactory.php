<?php

namespace Database\Factories;

use App\Enums\MailDomainStatus;
use App\Models\MailDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailDomain>
 */
class MailDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'.com.tr',
            'status' => MailDomainStatus::Active,
            'spam_filter' => true,
            'manage_dns' => true,
        ];
    }
}
