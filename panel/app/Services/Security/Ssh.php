<?php

namespace App\Services\Security;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;

/**
 * The server's SSH settings Servik manages: whether root may log in (and
 * how) and whether passwords are accepted at all. SFTP accounts keep
 * their passwords either way. The agent refuses to turn passwords off
 * while no administrator account that may log in has an SSH key.
 */
class Ssh
{
    public const ROOT_LOGIN = [
        'prohibit-password' => 'Yalnızca SSH anahtarıyla',
        'no' => 'Kapalı',
        'yes' => 'Parola ya da anahtarla',
    ];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * sshd's effective settings: installed, root_login, password_auth,
     * ports, managed and key_accounts.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(): array
    {
        $status = (array) $this->agent->call('ssh.status', [], 30);
        $ports = array_values(array_map('intval', $status['ports'] ?? []));
        if ($ports !== [] && $ports !== app(Firewall::class)->sshPorts()) {
            Setting::put('security.ssh_ports', $ports);
            try {
                app(Firewall::class)->reapply();
            } catch (AgentException) {
                // The firewall page shows the difference and applies on save.
            }
        }

        return $status;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function apply(string $rootLogin, bool $passwordAuth): array
    {
        return (array) $this->agent->call('ssh.apply', ['root_login' => $rootLogin, 'password_auth' => $passwordAuth], config('servik.agent.job_timeout'));
    }

    public static function message(AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => 'Sunucu ajanına ulaşılamıyor.',
            str_contains($e->reason, 'no administrator account') => 'Sunucuda SSH anahtarı olan bir yönetici hesabı yok. Parolayla girişi kapatmadan önce root ya da sudo yetkili bir hesaba anahtar ekleyin; yoksa sunucuya SSH ile giremezsiniz.',
            str_contains($e->reason, 'not installed') => 'SSH sunucusu kurulu değil.',
            str_contains($e->reason, 'sshd rejected') => 'SSH sunucusu yeni ayarı kabul etmedi; önceki ayar korundu.',
            default => 'SSH ayarı uygulanamadı: '.$e->reason,
        };
    }
}
