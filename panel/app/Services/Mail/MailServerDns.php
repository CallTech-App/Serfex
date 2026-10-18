<?php

namespace App\Services\Mail;

use App\Enums\ZoneStatus;
use App\Models\DnsZone;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;

/**
 * Points the mail server's own name at this server when that name lives in
 * a zone hosted here (mail.servik.example in servik.example), so mail
 * clients, webmail and Let's Encrypt find it.
 */
class MailServerDns
{
    public function __construct(
        private readonly Zones $zones,
        private readonly DnsSettings $dns,
    ) {}

    public function apply(string $hostname): ?string
    {
        $zone = DnsZone::where('name', $hostname)->first() ?? $this->zones->parentZone($hostname);
        if ($zone === null || $zone->status === ZoneStatus::Deleting) {
            return null;
        }
        $name = $hostname === $zone->name ? '@' : substr($hostname, 0, -strlen('.'.$zone->name));
        $at = $zone->records()->where('name', $name);
        if ((clone $at)->where('type', 'CNAME')->exists()) {
            return null;
        }
        $wanted = [];
        foreach (['A' => $this->dns->ipv4(), 'AAAA' => $this->dns->ipv6()] as $type => $ip) {
            if ($ip !== null && (clone $at)->where('type', $type)->doesntExist()) {
                $wanted[] = ['name' => $name, 'type' => $type, 'content' => $ip, 'ttl' => $zone->default_ttl];
            }
        }
        if ($wanted === []) {
            return null;
        }

        return $this->zones->change($zone, fn (DnsZone $zone) => $zone->records()->createMany($wanted));
    }
}
