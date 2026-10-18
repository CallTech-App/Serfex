<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the panel's administrator, or resets it. M1 has a single admin
 * account; customer and reseller logins come later.
 */
#[Signature('servik:admin {--email= : E-mail address used to sign in} {--name= : Display name} {--password= : Password (prompted when omitted)}')]
#[Description('Create or reset the Servik administrator account')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $admin = User::query()->oldest('id')->first();

        $email = $this->option('email') ?? text(
            label: 'E-mail address',
            default: $admin?->email ?? '',
            required: true,
        );
        $name = $this->option('name') ?? text(
            label: 'Name',
            default: $admin?->name ?? 'Administrator',
            required: true,
        );
        $password = $this->option('password') ?? password(
            label: 'Password',
            required: true,
            hint: 'At least 12 characters.',
        );

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($admin === null) {
            User::create(compact('email', 'name', 'password'));
            $this->components->info("Administrator {$email} created.");
        } else {
            $admin->update(compact('email', 'name', 'password'));
            $this->components->info("Administrator {$email} updated.");
        }

        return self::SUCCESS;
    }
}
