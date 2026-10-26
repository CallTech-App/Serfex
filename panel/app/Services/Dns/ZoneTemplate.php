<?php

namespace App\Services\Dns;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Support\DnsRecords;

/**
 * Builds the starting records of a new zone from the server-wide settings.
 */
class ZoneTemplate
{
    public function __construct(
        private readonly DnsSettings $settings,
        private readonly AgentClient $agent,
    ) {}

    /**
     * Name servers a new zone gets.
     *
     * @return list<string> absolute, with trailing dot
     */
    public function nameservers(string $zone): array
    {
        $ns = $this->settings->nameservers() ?: ['ns1.'.$zone, 'ns2.'.$zone];

        return array_map(fn ($h) => rtrim($h, '.').'.', $ns);
    }

    /**
     * Starting records for $zone: NS records, address records for name
     * servers inside the zone (ns1.<zone>, at their own address when they
     * have one), then the template rows.
     *
     * @param  bool  $onlyNameservers  for an empty zone (NS and SOA only)
     * @return list<array{name: string, type: string, content: string, priority: ?int, ttl: int}>
     */
    public function records(string $zone, bool $onlyNameservers = false): array
    {
        $this->detectAddresses();
        $ttl = $this->settings->defaultTtl();
        $ip = ['<ip4>' => $this->settings->ipv4(), '<ip6>' => $this->settings->ipv6()];

        $records = [];
        $add = function (string $name, string $type, string $content, ?int $priority = null, ?int $rowTtl = null) use (&$records, $ttl) {
            foreach ($records as $r) {
                if ($r['name'] === $name && $r['type'] === $type && $r['content'] === $content) {
                    return;
                }
            }
            $records[] = ['name' => $name, 'type' => $type, 'content' => $content, 'priority' => $priority, 'ttl' => $rowTtl ?? $ttl];
        };

        $nameservers = $this->nameservers($zone);
        foreach ($nameservers as $ns) {
            $add('@', 'NS', $ns);
        }
        $own = $this->settings->nameserverIps();
        foreach ($nameservers as $ns) {
            if (str_ends_with($ns, '.'.$zone.'.')) {
                $label = substr($ns, 0, -strlen('.'.$zone.'.'));
                // A name server on an address of its own gets only that A
                // record; this server's IPv6 address may not be its.
                if (($ip4 = $own[rtrim($ns, '.')] ?? null) !== null && $ip4 !== $ip['<ip4>']) {
                    $add($label, 'A', $ip4);

                    continue;
                }
                foreach (['A' => '<ip4>', 'AAAA' => '<ip6>'] as $type => $placeholder) {
                    if ($ip[$placeholder]) {
                        $add($label, $type, $ip[$placeholder]);
                    }
                }
            }
        }
        if ($onlyNameservers) {
            return $records;
        }

        foreach ($this->settings->template() as $row) {
            $content = str_replace('<domain>', $zone, $row['content']);
            if (str_contains($content, '<ip4>') || str_contains($content, '<ip6>')) {
                $value = $ip[trim($content)] ?? null;
                if ($value === null) {
                    continue;
                }
                $content = $value;
            }
            [$content, $error] = DnsRecords::normalizeContent($row['type'], $content, $zone);
            $name = DnsRecords::normalizeName(str_replace('<domain>', $zone, $row['name']), $zone);
            if ($error !== null || $name === null) {
                continue;
            }
            // A CNAME cannot share its name with other records.
            if ($row['type'] === 'CNAME' && collect($records)->contains('name', $name)) {
                continue;
            }
            $add($name, $row['type'], $content, $row['priority'] ?? null, $row['ttl'] ?? null);
        }

        return $records;
    }

    /**
     * Ask the agent for the server's public addresses the first time a zone
     * is built, so A and AAAA records work without visiting the settings.
     */
    public function detectAddresses(): void
    {
        if ($this->settings->hasAddresses()) {
            return;
        }
        try {
            $found = $this->agent->call('system.addresses');
        } catch (AgentException) {
            return;
        }
        $this->settings->save([
            'ipv4' => $found['ipv4'][0] ?? null,
            'ipv6' => $found['ipv6'][0] ?? null,
        ]);
    }
}
