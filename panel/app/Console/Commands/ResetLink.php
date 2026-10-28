<?php

namespace App\Console\Commands;

use App\Services\Security\PasswordReset;
use App\Services\Setup\FirstRun;
use App\Support\When;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes a one-time password reset link for any account and prints it:
 * `sudo serfex reset-password <e-posta>`, for when the panel can't send
 * mail or the mail doesn't arrive. Whoever can run this can already
 * change the panel, so the link needs nothing else.
 */
#[Signature('serfex:reset-link {email : The account\'s e-mail address} {--json : Print the link as JSON (serfex reset-password)}')]
#[Description('Make a one-time link that sets a new password for an account')]
class ResetLink extends Command
{
    public function handle(PasswordReset $resets, FirstRun $firstRun): int
    {
        $user = $resets->account((string) $this->argument('email'));
        if ($user === null) {
            $message = __(':email adresiyle bir hesap yok.', ['email' => $this->argument('email')]);
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        ['urls' => $urls, 'expires_at' => $expires] = $resets->linksForServer($user, $firstRun->panelUrls());
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'email' => $user->email,
                'url' => $urls[0],
                'urls' => $urls,
                'expires_at' => $expires->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(__(':email için parola sıfırlama bağlantısı (bir kez kullanılır, :until tarihine kadar geçerli):', ['email' => $user->email, 'until' => When::dateTime($expires)]));
        foreach ($urls as $url) {
            $this->line('  '.$url);
        }

        return self::SUCCESS;
    }
}
