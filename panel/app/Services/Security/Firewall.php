<?php

namespace App\Services\Security;

use App\Models\DnsZone;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Databases\DatabaseServers;
use App\Services\Mail\MailSettings;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Settings\PanelSettings;
use Illuminate\Support\Str;

/**
 * The port firewall (Güvenlik → Güvenlik duvarı): while it is on, the
 * server answers only on the ports its rules open. Servik's own services
 * have ready rules (the panel's cannot be closed); administrators add
 * their own, open to everyone or to some addresses, or refusing some
 * addresses. IP blocks (Canlı İzleme) are separate and come first.
 *
 * The agent refuses rules that would cut the administrator's own
 * connection to the panel, so a mistake cannot lock them out.
 */
class Firewall
{
    private const KEY = 'security.firewall';

    public const PROTOCOLS = ['tcp' => 'TCP', 'udp' => 'UDP', 'both' => 'TCP ve UDP'];

    public const MAX_RULES = 80;

    public const PORTS_PATTERN = '/^\d{1,5}(?:-\d{1,5})?(?:\s*,\s*\d{1,5}(?:-\d{1,5})?)*$/';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The saved settings: on or off, which ready rules are on, and the
     * administrators' own rules.
     *
     * @return array{enabled: bool, services: array<string, bool>, rules: list<array<string, mixed>>}
     */
    public function settings(): array
    {
        $s = (array) Setting::get(self::KEY, []);

        return [
            'enabled' => (bool) ($s['enabled'] ?? false),
            'services' => (array) ($s['services'] ?? []),
            'rules' => array_values((array) ($s['rules'] ?? [])),
        ];
    }

    public function enabled(): bool
    {
        return $this->settings()['enabled'];
    }

    /**
     * Servik's ready rules: id => name, ports, protocol, whether it is on,
     * whether it may be turned off, and why it exists.
     *
     * @param  array{enabled: bool, services: array<string, bool>, rules: list<array<string, mixed>>}|null  $settings  the saved ones when null
     * @return array<string, array<string, mixed>>
     */
    public function services(?array $settings = null): array
    {
        $on = ($settings ?? $this->settings())['services'];
        $panel = implode(', ', app(PanelSettings::class)->ports());
        $mail = app(MailSettings::class)->isSetUp();
        $dns = (Setting::get('dns.server', [])['state'] ?? null) === 'ready' || DnsZone::exists();
        $list = [
            'panel' => ['Servik paneli', (string) $panel, 'tcp', true, true, 'Panel, phpMyAdmin ve Adminer; kapatılamaz'],
            'web' => ['Web siteleri', '80, 443', 'tcp', true, false, 'HTTP ve HTTPS'],
            'ssh' => ['SSH ve SFTP', implode(', ', $this->sshPorts()), 'tcp', true, false, 'Sunucu yönetimi ve FTP hesapları'],
            'mail' => ['E-posta', '25, 465, 587, 110, 995, 143, 993', 'tcp', $mail, false, 'SMTP, POP3 ve IMAP'],
            'dns' => ['DNS sunucusu', '53', 'both', $dns, false, 'Alan adlarınızın ad sunucusu'],
            'databases' => ['Uzak veritabanı bağlantısı', '3306, 5432', 'tcp', false, false, 'MySQL/MariaDB ve PostgreSQL; yalnızca dışarıdan bağlanılacaksa'],
        ];
        $out = [];
        foreach ($list as $id => [$name, $ports, $protocol, $default, $locked, $help]) {
            $out[$id] = [
                'id' => $id, 'name' => $name, 'ports' => $ports, 'protocol' => $protocol, 'help' => $help, 'locked' => $locked,
                'enabled' => $locked || (bool) ($on[$id] ?? $default),
            ];
        }

        return $out;
    }

    /**
     * SSH's ports as last read from the server.
     *
     * @return list<int>
     */
    public function sshPorts(): array
    {
        $ports = (array) Setting::get('security.ssh_ports', [22]);

        return $ports ?: [22];
    }

    /**
     * The rules as the agent wants them.
     *
     * @param  array{enabled: bool, services: array<string, bool>, rules: list<array<string, mixed>>}  $settings
     * @return list<array<string, mixed>>
     */
    private function compile(array $settings): array
    {
        $out = [];
        foreach ($this->services($settings) as $id => $s) {
            if ($s['enabled']) {
                $out[] = ['id' => 'svc-'.$id, 'name' => $s['name'], 'ports' => $s['ports'], 'protocol' => $s['protocol'], 'sources' => [], 'action' => 'allow'];
            }
        }
        foreach ($settings['rules'] as $r) {
            if ($r['enabled'] ?? true) {
                $out[] = ['id' => $r['id'], 'name' => $r['name'], 'ports' => $r['ports'], 'protocol' => $r['protocol'], 'sources' => array_values($r['sources'] ?? []), 'action' => $r['action']];
            }
        }

        return $out;
    }

    /**
     * Saves new settings and applies them. Nothing changes when the agent
     * refuses them (for example because they would lock $ip out).
     *
     * @param  array{enabled: bool, services: array<string, bool>, rules: list<array<string, mixed>>}  $settings
     *
     * @throws AgentException
     * @throws MonitorException with a message for the person
     */
    public function save(array $settings, ?string $ip): void
    {
        $params = ['enabled' => $settings['enabled'], 'rules' => $this->compile($settings)];
        if ($ip !== null && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $params['protect'] = ['address' => $ip, 'port' => app(DatabaseServers::class)->managerPort()];
        }
        try {
            $this->agent->call('firewall.rules.apply', $params, config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() === AgentException::INVALID_PARAMS) {
                throw new MonitorException(self::message($e), previous: $e);
            }
            throw $e;
        }
        Setting::put(self::KEY, $settings);
    }

    /**
     * Applies the saved settings again (after the panel's port or SSH's
     * ports changed).
     *
     * @throws AgentException
     */
    public function reapply(): void
    {
        $settings = $this->settings();
        if ($settings['enabled']) {
            $this->agent->call('firewall.rules.apply', ['enabled' => true, 'rules' => $this->compile($settings)], config('servik.agent.job_timeout'));
        }
    }

    /**
     * Whether the filter is in force on the server, and what listens.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function status(): array
    {
        return (array) $this->agent->call('firewall.rules', [], 30);
    }

    /**
     * Checks a rule; returns an error per field.
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, string>
     */
    public function problems(array $rule): array
    {
        $out = [];
        if (trim((string) $rule['name']) === '' || mb_strlen((string) $rule['name']) > 80) {
            $out['name'] = 'Kurala 1-80 karakterlik bir ad verin.';
        }
        $ports = trim((string) $rule['ports']);
        $valid = preg_match(self::PORTS_PATTERN, $ports);
        foreach ($valid ? explode(',', $ports) : [] as $part) {
            [$lo, $hi] = array_pad(array_map('intval', explode('-', trim($part))), 2, null);
            $hi ??= $lo;
            $valid = $valid && $lo >= 1 && $hi <= 65535 && $lo <= $hi;
        }
        if (! $valid) {
            $out['ports'] = 'Portları 8080 ya da 3000, 3001 ya da 49152-49352 gibi yazın.';
        }
        if (! array_key_exists($rule['protocol'], self::PROTOCOLS)) {
            $out['protocol'] = 'Bir protokol seçin.';
        }
        foreach ($rule['sources'] as $source) {
            try {
                IpBlocks::normalize($source);
            } catch (MonitorException) {
                $out['sources'] = "\"{$source}\" bir IP adresi ya da aralığı değil.";
            }
        }
        if (count($rule['sources']) > 50) {
            $out['sources'] = 'En fazla 50 adres yazılabilir.';
        }
        if ($rule['action'] === 'deny' && $rule['sources'] === []) {
            $out['sources'] = 'Engelleme kuralı için en az bir adres yazın; portu herkese kapatmak için kuralı silin.';
        }

        return $out;
    }

    public static function newId(): string
    {
        return 'r-'.Str::lower(Str::random(10));
    }

    public static function message(AgentException $e): string
    {
        return match (true) {
            $e instanceof AgentUnavailableException => 'Sunucu ajanına ulaşılamıyor.',
            str_contains($e->reason, 'your own connection to the panel') => 'Bu kurallar panele şu anki bağlantınızı keserdi; hiçbir şey değiştirilmedi.',
            default => 'Güvenlik duvarı ayarı uygulanamadı: '.$e->reason,
        };
    }
}
