<?php

namespace App\Services\Dns;

use App\Models\DnsZone;

/**
 * The name servers' own IPv4 addresses (ns2 on a second server, say) and
 * their address records in the zones hosted here.
 */
class NameserverIps
{
    public function __construct(
        private readonly DnsSettings $settings,
        private readonly Zones $zones,
        private readonly ZoneTemplate $template,
    ) {}

    /**
     * Save the addresses of the given name servers, an empty one meaning
     * the server's IPv4 address, and move each name server's A record in
     * its zone here (ns2 in ornek.com.tr) from its old address to the new
     * one. Returns how many zones changed.
     *
     * @param  array<string, ?string>  $ips  name => IPv4 address
     */
    public function save(array $ips): int
    {
        $this->template->detectAddresses();
        $server = $this->settings->ipv4();
        $before = $this->settings->nameserverIps();
        $after = [];
        foreach ($ips as $host => $ip) {
            $host = rtrim(mb_strtolower(trim($host)), '.');
            $ip = trim((string) $ip);
            if ($host !== '' && $ip !== '' && $ip !== $server) {
                $after[$host] = $ip;
            }
        }
        $this->settings->save(['nameserver_ips' => $after]);

        $changed = [];
        foreach (array_keys($ips) as $host) {
            $host = rtrim(mb_strtolower(trim($host)), '.');
            $old = $before[$host] ?? $server;
            $new = $after[$host] ?? $server;
            if ($old === $new || $new === null || ($zone = $this->zones->parentZone($host)) === null) {
                continue;
            }
            $label = substr($host, 0, -strlen('.'.$zone->name));
            $this->zones->change($zone, function (DnsZone $zone) use ($label, $old, $new, $server) {
                $a = $zone->records()->where('name', $label)->where('type', 'A');
                if ((clone $a)->exists()) {
                    (clone $a)->where('content', $old)->update(['content' => $new]);
                } else {
                    $zone->records()->create(['name' => $label, 'type' => 'A', 'content' => $new, 'ttl' => $zone->default_ttl]);
                }
                // This server's IPv6 address goes with its IPv4 address.
                $ipv6 = $this->settings->ipv6();
                $aaaa = $zone->records()->where('name', $label)->where('type', 'AAAA');
                if ($ipv6 && $new !== $server) {
                    (clone $aaaa)->where('content', $ipv6)->delete();
                } elseif ($ipv6 && ! (clone $aaaa)->exists()) {
                    $zone->records()->create(['name' => $label, 'type' => 'AAAA', 'content' => $ipv6, 'ttl' => $zone->default_ttl]);
                }
            });
            $changed[$zone->id] = true;
        }

        return count($changed);
    }
}
