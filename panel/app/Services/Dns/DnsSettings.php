<?php

namespace App\Services\Dns;

use App\Models\Setting;

/**
 * Server-wide DNS settings: name servers, the server's addresses, SOA
 * defaults and the record template for new zones.
 */
class DnsSettings
{
    public const DEFAULT_SOA = ['refresh' => 10800, 'retry' => 3600, 'expire' => 604800, 'minimum' => 3600];

    /**
     * Records every new zone starts with, besides its NS records (which come
     * from the name server setting). <domain>, <ip4> and <ip6> are replaced
     * per zone; rows whose placeholder has no value are skipped. The e-mail
     * milestone adds mail, MX, SPF, DKIM and DMARC rows here.
     */
    public const DEFAULT_TEMPLATE = [
        ['name' => '@', 'type' => 'A', 'content' => '<ip4>', 'priority' => null, 'ttl' => null],
        ['name' => '@', 'type' => 'AAAA', 'content' => '<ip6>', 'priority' => null, 'ttl' => null],
        ['name' => 'www', 'type' => 'CNAME', 'content' => '<domain>.', 'priority' => null, 'ttl' => null],
    ];

    /**
     * Name servers for new zones. Empty means each zone uses its own
     * ns1.<zone> and ns2.<zone>, pointing at this server.
     *
     * @return list<string>
     */
    public function nameservers(): array
    {
        return array_values(Setting::get('dns.nameservers', []));
    }

    public function ipv4(): ?string
    {
        return Setting::get('dns.ipv4');
    }

    public function ipv6(): ?string
    {
        return Setting::get('dns.ipv6');
    }

    public function defaultTtl(): int
    {
        return (int) Setting::get('dns.default_ttl', 3600);
    }

    /**
     * SOA mailbox for new zones; null means hostmaster@<zone>.
     */
    public function hostmaster(): ?string
    {
        return Setting::get('dns.hostmaster') ?: null;
    }

    /**
     * @return array{refresh: int, retry: int, expire: int, minimum: int}
     */
    public function soa(): array
    {
        return array_merge(self::DEFAULT_SOA, Setting::get('dns.soa', []));
    }

    /**
     * @return list<array{name: string, type: string, content: string, priority: ?int, ttl: ?int}>
     */
    public function template(): array
    {
        return array_values(Setting::get('dns.template', self::DEFAULT_TEMPLATE));
    }

    /**
     * True once an administrator saved the settings page, or the addresses
     * were detected.
     */
    public function hasAddresses(): bool
    {
        return $this->ipv4() !== null || $this->ipv6() !== null;
    }

    /**
     * @param  array{nameservers?: list<string>, ipv4?: ?string, ipv6?: ?string, default_ttl?: int, hostmaster?: ?string, soa?: array<string, int>, template?: list<array<string, mixed>>}  $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::put('dns.'.$key, $value);
        }
    }
}
