<?php

namespace App\Services\Ssl;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsSettings;

/**
 * Checks whether host names resolve to this server, the way Let's Encrypt
 * will see them: through public DNS, not through the panel's own zones.
 *
 * A name behind Cloudflare's proxy resolves to Cloudflare, never to this
 * server. Cloudflare passes Let's Encrypt's HTTP validation on to the
 * origin (over HTTP, or over HTTPS to the default server, which answers it
 * too), so such a name counts as pointing here; if its origin is another
 * server, validation fails and is retried like any other failure.
 */
class DnsCheck
{
    /**
     * Cloudflare's proxy addresses (https://www.cloudflare.com/ips/).
     */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var list<string>|null */
    private ?array $addresses = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly DnsSettings $settings,
    ) {}

    /**
     * @return array{ok: bool, found: list<string>, proxied: bool}
     */
    public function check(string $name): array
    {
        if (! config('serfex.ssl.check_dns')) {
            return ['ok' => true, 'found' => [], 'proxied' => false];
        }
        $found = $this->lookup($name);
        if (array_intersect($found, $this->serverAddresses()) !== []) {
            return ['ok' => true, 'found' => $found, 'proxied' => false];
        }
        $proxied = $found !== [] && array_filter($found, fn ($ip) => ! self::isCloudflare($ip)) === [];

        return ['ok' => $proxied, 'found' => $found, 'proxied' => $proxied];
    }

    /**
     * Whether $ip is one of Cloudflare's proxy addresses.
     */
    public static function isCloudflare(string $ip): bool
    {
        $addr = @inet_pton($ip);
        if ($addr === false) {
            return false;
        }
        foreach (self::CLOUDFLARE as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $base = (string) inet_pton($net);
            if (strlen($base) !== strlen($addr)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($addr, 0, $bytes) !== substr($base, 0, $bytes)) {
                continue;
            }
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($addr[$bytes]) & $mask) === (ord($base[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
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
