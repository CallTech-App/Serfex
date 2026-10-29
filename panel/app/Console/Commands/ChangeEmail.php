<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Security\EmailChange;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Changes an account's sign-in address at once:
 * `sudo serfex change-email <eski> <yeni>`, for when the confirmation
 * link mailed to the new address doesn't arrive. Whoever can run this can
 * already change the panel, so no password or link is asked.
 */
#[Signature('serfex:change-email {email : The account\'s current e-mail address} {new : The new e-mail address} {--json : Print the result as JSON (serfex change-email)}')]
#[Description('Change an account\'s e-mail address without a confirmation link')]
class ChangeEmail extends Command
{
    public function handle(EmailChange $changes): int
    {
        $user = User::whereRaw('lower(email) = ?', [Str::lower(trim((string) $this->argument('email')))])->whereNull('removed_at')->first();
        try {
            if ($user === null) {
                throw new RuntimeException(__(':email adresiyle bir hesap yok.', ['email' => $this->argument('email')]));
            }
            $old = $user->email;
            $changes->changeOnServer($user, (string) $this->argument('new'));
        } catch (RuntimeException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['old' => $old, 'email' => $user->email], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->info(__('E-posta adresi :old yerine :new oldu.', ['old' => $old, 'new' => $user->email]));
        }

        return self::SUCCESS;
    }
}
