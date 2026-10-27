<?php

namespace App\Services\Ssl;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsSettings;

/**
 * Checks whether host names resolve to this server, the way Let's Encrypt
 * will see them: through public DNS, not through the panel's own zones.
 */
class DnsCheck
{
    /** @var list<string>|null */
    private ?array $addresses = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly DnsSettings $settings,
    ) {}

    /**
     * @return array{ok: bool, found: list<string>}
     */
    public function check(string $name): array
    {
        if (! config('serfex.ssl.check_dns')) {
            return ['ok' => true, 'found' => []];
        }
        $found = $this->lookup($name);

        return ['ok' => array_intersect($found, $this->serverAddresses()) !== [], 'found' => $found];
    }

    /**
     * The A and AAAA addresses of $name, CNAMEs followed.
     *
     * @return list<string>
     */
    public function lookup(string $name): array
    {
        $records = @dns_get_record($name, DNS_A | DNS_AAAA);
        $out = [];
        foreach ($records ?: [] as $r) {
            $ip = $r['ip'] ?? $r['ipv6'] ?? null;
            if ($ip !== null && in_array($r['type'] ?? '', ['A', 'AAAA'], true)) {
                $out[] = inet_ntop(inet_pton($ip)) ?: $ip;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Addresses this server answers on: the ones set in the DNS settings
     * (the public address behind NAT) and the ones the agent sees.
     *
     * @return list<string>
     */
    public function serverAddresses(): array
    {
        if ($this->addresses !== null) {
            return $this->addresses;
        }
        $list = array_filter([$this->settings->ipv4(), $this->settings->ipv6()]);
        try {
            $found = $this->agent->call('system.addresses');
            $list = [...$list, ...($found['ipv4'] ?? []), ...($found['ipv6'] ?? [])];
        } catch (AgentException) {
            // The settings are enough when they are filled in.
        }

        return $this->addresses = array_values(array_unique(array_map(
            fn ($ip) => inet_ntop((string) inet_pton($ip)) ?: $ip,
            array_filter($list, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false),
        )));
    }
}
