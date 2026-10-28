<?php

namespace App\Services\Dns;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;

/**
 * Secondary name servers: the addresses PowerDNS lets transfer every zone
 * and tells about each change. The agent keeps the list (the Services
 * page shows it too) and switches the zones and their catalog with it.
 */
class Secondaries
{
    /** How many addresses may transfer zones (the agent's limit). */
    public const MAX = 32;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The secondaries as the agent reports them, or null while the DNS
     * server cannot answer.
     *
     * @return array{addresses: list<string>, notify: list<string>, catalog: string, zones: int}|null
     */
    public function get(): ?array
    {
        try {
            $res = (array) $this->agent->call('dns.secondaries.get');
        } catch (AgentException) {
            return null;
        }

        return self::shape($res);
    }

    /**
     * Allow these addresses; PowerDNS restarts for it. Returns the new
     * state, or why it failed.
     *
     * @param  list<string>  $addresses
     * @return array{addresses: list<string>, notify: list<string>, catalog: string, zones: int}|string
     */
    public function set(array $addresses): array|string
    {
        try {
            $res = (array) $this->agent->call('dns.secondaries.set', ['addresses' => array_values($addresses)], 120);
        } catch (AgentUnavailableException) {
            return __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.');
        } catch (AgentException $e) {
            return $e->reason;
        }

        return self::shape($res);
    }

    /**
     * The addresses typed in a field: one per line (commas and spaces work
     * too), blank ones left out.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($text)) ?: [], fn ($v) => $v !== ''));
    }

    /** An IPv4 or IPv6 address, or a network in CIDR form. */
    public static function isAddress(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /**
     * @param  array<string, mixed>  $res
     * @return array{addresses: list<string>, notify: list<string>, catalog: string, zones: int}
     */
    private static function shape(array $res): array
    {
        return [
            'addresses' => array_values((array) ($res['addresses'] ?? [])),
            'notify' => array_values((array) ($res['notify'] ?? [])),
            'catalog' => (string) ($res['catalog'] ?? ''),
            'zones' => (int) ($res['zones'] ?? 0),
        ];
    }
}
