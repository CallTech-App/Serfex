<?php

namespace App\Services\Security;

use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Databases\DatabaseServers;
use App\Services\Settings\PanelSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The server's SSH settings Serfex manages: whether root may log in (and
 * how) and whether passwords are accepted at all. SFTP accounts keep
 * their passwords either way. The agent refuses to turn passwords off
 * while no administrator account that may log in has an SSH key.
 *
 * Its port changes in two steps so nobody is locked out: the new port
 * opens next to the old one, the administrator logs in through it, and
 * only then is the old one closed (or the change is dropped). Until then
 * both stay open; there is no deadline, since both open is safe.
 */
class Ssh
{
    public const ROOT_LOGIN = [
        'prohibit-password' => 'Yalnızca SSH anahtarıyla',
        'no' => 'Kapalı',
        'yes' => 'Parola ya da anahtarla',
    ];

    /** The port change waiting to be confirmed: {from, to, at}. */
    private const PORT_CHANGE = 'security.ssh_port_change';

    /**
     * Ports of services Serfex runs or may install: web, mail, DNS,
     * databases, caches, Apache behind Nginx, the update helper.
     */
    public const RESERVED_PORTS = [53, 80, 443, 25, 465, 587, 110, 995, 143, 993, 4190, 3306, 5432, 5433, 6379, 11211, 7080, 8081, 11332, 11333, 11334];

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
        return (array) $this->agent->call('ssh.apply', ['root_login' => $rootLogin, 'password_auth' => $passwordAuth], config('serfex.agent.job_timeout'));
    }

    /**
     * SSH's port now: the old one while a change waits.
     */
    public function port(): int
    {
        $change = $this->portChange();

        return $change ? (int) $change['from'] : app(Firewall::class)->sshPorts()[0];
    }

    /**
     * @return array{from: int, to: int, at: string}|null
     */
    public function portChange(): ?array
    {
        return Setting::get(self::PORT_CHANGE);
    }

    /**
     * Why $port can't be SSH's, or null.
     */
    public function portProblem(int $port): ?string
    {
        $panel = app(PanelSettings::class)->ports();

        return match (true) {
            $port !== 22 && ($port < 1024 || $port > 65535) => __('Port 22 ya da 1024 ile 65535 arasında olmalı.'),
            $port === $this->port() => __('SSH zaten bu portta.'),
            in_array($port, $panel, true) || $port === app(DatabaseServers::class)->managerPort() => __(':port panelin portu; başka bir port seçin.', ['port' => $port]),
            in_array($port, self::RESERVED_PORTS, true) => __(':port web, e-posta, DNS ya da veritabanı hizmetlerinin portu; başka bir port seçin.', ['port' => $port]),
            default => null,
        };
    }

    /**
     * Opens SSH on $port too; the old port keeps working until the change
     * is confirmed or dropped.
     *
     * @throws AgentException
     */
    public function changePort(int $port): void
    {
        $from = $this->port();
        $this->applyPorts([$port, $from]);
        Setting::put(self::PORT_CHANGE, ['from' => $from, 'to' => $port, 'at' => now()->toIso8601String()]);
    }

    /**
     * Keeps the new port and closes the old one.
     *
     * @throws AgentException
     */
    public function confirmPort(): void
    {
        $change = $this->portChange();
        if ($change !== null) {
            $this->applyPorts([(int) $change['to']]);
            Setting::put(self::PORT_CHANGE, null);
        }
    }

    /**
     * Goes back to the old port only.
     *
     * @throws AgentException
     */
    public function revertPort(): void
    {
        $change = $this->portChange();
        if ($change !== null) {
            $this->applyPorts([(int) $change['from']]);
            Setting::put(self::PORT_CHANGE, null);
        }
    }

    /**
     * Makes sshd listen on $ports, then lets the firewall, the SFTP
     * connection details and Fail2ban (the agent) follow.
     *
     * @param  list<int>  $ports
     *
     * @throws AgentException
     */
    private function applyPorts(array $ports): void
    {
        $status = (array) $this->agent->call('ssh.ports.apply', ['ports' => $ports], config('serfex.agent.job_timeout'));
        Setting::put('security.ssh_ports', array_values(array_map('intval', $status['ports'] ?? $ports)));
        Cache::forget('serfex.ftp.port');
        try {
            app(Firewall::class)->reapply();
        } catch (AgentException $e) {
            report($e);
        }
    }

    public static function message(AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => __('Sunucu ajanına ulaşılamıyor.'),
            str_contains($e->reason, 'no administrator account') => __('Sunucuda SSH anahtarı olan bir yönetici hesabı yok. Parolayla girişi kapatmadan önce root ya da sudo yetkili bir hesaba anahtar ekleyin; yoksa sunucuya SSH ile giremezsiniz.'),
            str_contains($e->reason, 'not installed') => __('SSH sunucusu kurulu değil.'),
            str_contains($e->reason, 'sshd rejected') => __('SSH sunucusu yeni ayarı kabul etmedi; önceki ayar korundu.'),
            str_contains($e->reason, 'in use by another program') => __('Bu portu sunucuda başka bir program kullanıyor; başka bir port seçin.'),
            str_contains($e->reason, 'does not answer on port') => __('SSH sunucusu yeni portta yanıt vermedi; önceki ayar geri yüklendi, SSH eski portunda çalışıyor.'),
            str_contains($e->reason, 'ListenAddress') => __('sshd_config dosyasındaki bir ListenAddress satırı kendi portunu belirtiyor; o satırı elle düzeltin. Hiçbir şey değiştirilmedi.'),
            default => __('SSH ayarı uygulanamadı: :reason', ['reason' => $e->reason]),
        };
    }
}
