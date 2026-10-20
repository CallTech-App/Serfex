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
 * Creates the panel's first administrator, or resets it. Customer logins
 * are never touched.
 */
#[Signature('servik:admin {--email= : E-mail address used to sign in} {--name= : Display name} {--password= : Password (prompted when omitted)}')]
#[Description('Create or reset the Servik administrator account')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $admin = User::query()->where('is_admin', true)->whereNull('customer_id')->oldest('id')->first();

        $email = $this->option('email') ?? text(
            label: __('E-posta adresi'),
            default: $admin?->email ?? '',
            required: true,
        );
        $name = $this->option('name') ?? text(
            label: __('Ad'),
            default: $admin?->name ?? __('Yönetici'),
            required: true,
        );
        $password = $this->option('password') ?? password(
            label: __('Parola'),
            required: true,
            hint: __('En az 12 karakter.'),
        );

        $validator = Validator::make(
            compact('email', 'name', 'password'),
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
            ],
            attributes: ['email' => __('e-posta adresi'), 'name' => __('ad'), 'password' => __('parola')],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        if ($admin === null) {
            User::create(compact('email', 'name', 'password'));
            $this->components->info(__(':email yöneticisi oluşturuldu.', ['email' => $email]));
        } else {
            $admin->update(compact('email', 'name', 'password'));
            $this->components->info(__(':email yöneticisi güncellendi.', ['email' => $email]));
        }

        return self::SUCCESS;
    }
}
