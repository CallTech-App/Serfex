<?php

namespace Database\Factories;

use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Services\Mail\MailPassword;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mailbox>
 */
class MailboxFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mail_domain_id' => MailDomain::factory(),
            'name' => fake()->unique()->userName(),
            'password_hash' => MailPassword::hash('Gizli-parola-42'),
            'quota_mb' => 1024,
            'forward_to' => [],
            'keep_copy' => true,
        ];
    }
}
