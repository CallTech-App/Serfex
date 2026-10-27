<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cli\CliLogins;
use App\Services\Settings\ApiKeys;
use App\Services\Settings\PanelSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Signs the serfex command line in on the server itself: `serfex login
 * --local`, run as root, calls this to get a key of a full administrator
 * without a browser. Whoever can run it can already change the panel.
 */
#[Signature('serfex:cli-key {--email= : The administrator the key acts as (default: the first full administrator)} {--client= : The computer\'s name, shown on the account security page} {--read-only : A key that only reads}')]
#[Description('Make a command line key for an administrator on this server (serfex login --local)')]
class CliKey extends Command
{
    public function handle(ApiKeys $keys, PanelSettings $settings): int
    {
        $email = $this->option('email');
        $user = $email !== null
            ? User::where('email', mb_strtolower($email))->where('is_admin', true)->whereNull('removed_at')->first()
            : User::where('is_admin', true)->whereNull('removed_at')->where(fn ($q) => $q->where('role', 'full')->orWhereNull('role'))->oldest('id')->first();
        if ($user === null) {
            $this->components->error($email !== null ? __(':email adında bir yönetici yok.', ['email' => $email]) : __('Henüz yönetici yok. Kurulum bağlantısını sudo serfex setup-url ile alın ve yönetici hesabını panelde oluşturun.'));

            return self::FAILURE;
        }
        $client = trim((string) ($this->option('client') ?: (gethostname() ?: 'server')));
        $scope = $this->option('read-only') || $user->isReadOnly() ? 'read' : 'write';
        [, $plain] = $keys->create($user, __('Komut satırı: :client', ['client' => $client]), $scope, [], now()->addDays(CliLogins::KEY_DAYS), ApiKey::CLI, $client);
        $hostname = Setting::get('panel.hostname') ?: (Setting::get('server.hostname') ?: (gethostname() ?: 'localhost'));
        $port = $settings->port();

        $this->line((string) json_encode([
            'key' => $plain,
            'url' => 'https://'.$hostname.':'.$port,
            'connect' => '127.0.0.1:'.$port,
            'name' => $user->name,
            'email' => $user->email,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
