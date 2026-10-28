<?php

namespace App\Console\Commands;

use App\Services\Setup\FirstRun;
use App\Support\When;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Makes the one-time link that opens a new panel: install.sh prints it,
 * and `sudo serfex setup-url` makes a new one when it is lost. Whoever can
 * run this can already change the panel, so the link needs nothing else.
 */
#[Signature('serfex:setup-url {--json : Print the link as JSON (install.sh, serfex setup-url)}')]
#[Description('Make the one-time link that creates the first administrator')]
class SetupUrl extends Command
{
    public function handle(FirstRun $firstRun): int
    {
        if (! $firstRun->needsAdministrator()) {
            $message = __('Bu panelde zaten bir yönetici var; kendi e-posta ve parolanızla giriş yapın. Parolayı unuttuysanız giriş sayfasındaki Parolamı unuttum bağlantısını ya da sudo serfex reset-password <e-posta> komutunu kullanın.');
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        ['token' => $token, 'expires_at' => $expires] = $firstRun->newToken();
        $urls = array_map(fn ($base) => $base.'/install/'.$token, $firstRun->panelUrls());
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'url' => $urls[0],
                'urls' => $urls,
                'expires_at' => $expires->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info(__('Kurulum bağlantısı (bir kez kullanılır, :until tarihine kadar geçerli):', ['until' => When::dateTime($expires)]));
        foreach ($urls as $url) {
            $this->line('  '.$url);
        }

        return self::SUCCESS;
    }
}
