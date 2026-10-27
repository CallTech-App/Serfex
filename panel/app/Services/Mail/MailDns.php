<?php

namespace App\Services\Mail;

use App\Enums\ZoneStatus;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;
use App\Support\Badge;
use Illuminate\Support\Collection;

/**
 * The DNS records a mail domain needs (MX, SPF, DKIM, DMARC, the mail
 * address and what mail clients use to find their settings), written into
 * the domain's zone on this server and checked against public DNS.
 */
class MailDns
{
    /** DKIM selector the agent signs with. */
    public const DKIM_SELECTOR = 'serfex';

    public function __construct(
        private readonly Zones $zones,
        private readonly DnsSettings $dns,
        private readonly MailSettings $settings,
        private readonly PublicDns $public,
    ) {}

    /**
     * The zone on this server that holds the domain: its own or a parent's.
     */
    public function zoneFor(MailDomain $domain): ?DnsZone
    {
        $zone = DnsZone::where('name', $domain->name)->first() ?? $this->zones->parentZone($domain->name);

        return $zone !== null && $zone->status !== ZoneStatus::Deleting ? $zone : null;
    }

    /**
     * Records the domain should have. Names are absolute without the
     * trailing dot; content is in the panel's record form.
     *
     * The policy says which records already in the zone stand in for the
     * wanted one: "type" any record of the same name and type, "prefix" TXT
     * records starting the same way (one SPF or DMARC record per name),
     * "own" any TXT at the name (Serfex's DKIM key, always replaced), "name"
     * any record at the name (a CNAME stands alone).
     *
     * @return list<array{key: string, label: string, name: string, type: string, content: string, priority: ?int, policy: string, prefix?: string}>
     */
    public function wanted(MailDomain $domain): array
    {
        $d = $domain->name;
        $host = $this->settings->hostname();
        $ip4 = $this->dns->ipv4();
        $ip6 = $this->dns->ipv6();

        $spf = 'v=spf1 a mx'.($ip4 ? ' ip4:'.$ip4 : '').($ip6 ? ' ip6:'.$ip6 : '').' ~all';
        $rows = [
            ['key' => 'mx', 'label' => __('Gelen posta (MX)'), 'name' => $d, 'type' => 'MX', 'content' => 'mail.'.$d.'.', 'priority' => 10, 'policy' => 'type'],
        ];
        if ($ip4) {
            $rows[] = ['key' => 'mail-a', 'label' => __('Posta sunucusu adresi'), 'name' => 'mail.'.$d, 'type' => 'A', 'content' => $ip4, 'priority' => null, 'policy' => 'type'];
        }
        if ($ip6) {
            $rows[] = ['key' => 'mail-aaaa', 'label' => __('Posta sunucusu adresi (IPv6)'), 'name' => 'mail.'.$d, 'type' => 'AAAA', 'content' => $ip6, 'priority' => null, 'policy' => 'type'];
        }
        $rows[] = ['key' => 'spf', 'label' => 'SPF', 'name' => $d, 'type' => 'TXT', 'content' => $spf, 'priority' => null, 'policy' => 'prefix', 'prefix' => 'v=spf1'];
        if ($domain->dkim_record) {
            $rows[] = ['key' => 'dkim', 'label' => 'DKIM', 'name' => self::DKIM_SELECTOR.'._domainkey.'.$d, 'type' => 'TXT', 'content' => $domain->dkim_record, 'priority' => null, 'policy' => 'own'];
        }
        $rows[] = ['key' => 'dmarc', 'label' => 'DMARC', 'name' => '_dmarc.'.$d, 'type' => 'TXT', 'content' => 'v=DMARC1; p=none', 'priority' => null, 'policy' => 'prefix', 'prefix' => 'v=DMARC1'];
        if ($host !== null) {
            $rows[] = ['key' => 'autoconfig', 'label' => __('İstemci ayarları (Thunderbird)'), 'name' => 'autoconfig.'.$d, 'type' => 'CNAME', 'content' => $host.'.', 'priority' => null, 'policy' => 'name'];
            $rows[] = ['key' => 'autodiscover', 'label' => __('İstemci ayarları (Outlook)'), 'name' => 'autodiscover.'.$d, 'type' => 'CNAME', 'content' => $host.'.', 'priority' => null, 'policy' => 'name'];
            $rows[] = ['key' => 'srv-autodiscover', 'label' => __('İstemci ayarları (Outlook)'), 'name' => '_autodiscover._tcp.'.$d, 'type' => 'SRV', 'content' => '1 443 '.$host.'.', 'priority' => 0, 'policy' => 'type'];
            $rows[] = ['key' => 'srv-submissions', 'label' => __('Giden posta (SMTP)'), 'name' => '_submissions._tcp.'.$d, 'type' => 'SRV', 'content' => '1 465 '.$host.'.', 'priority' => 0, 'policy' => 'type'];
            $rows[] = ['key' => 'srv-imaps', 'label' => __('Gelen posta (IMAP)'), 'name' => '_imaps._tcp.'.$d, 'type' => 'SRV', 'content' => '1 993 '.$host.'.', 'priority' => 0, 'policy' => 'type'];
        }

        return $rows;
    }

    /**
     * Write the records that are missing (and Serfex's DKIM key) into the
     * zone. With $fix, records that differ are replaced too. Returns the
     * zone's undo token, or null when nothing changed or there is no zone.
     */
    public function apply(MailDomain $domain, bool $fix = false): ?string
    {
        $zone = $this->zoneFor($domain);
        if ($zone === null) {
            return null;
        }
        $records = $zone->records()->get();
        $plan = [];
        foreach ($this->wanted($domain) as $w) {
            $state = $this->zoneState($w, $records, $zone);
            if ($state['state'] === 'written' || ($state['state'] === 'different' && ! $fix && $w['policy'] !== 'own')) {
                continue;
            }
            // A CNAME cannot replace records of other types.
            if ($w['policy'] === 'name' && $state['others']->contains(fn (DnsRecord $r) => $r->type !== 'CNAME')) {
                continue;
            }
            $plan[] = [$w, $state['others']->modelKeys()];
        }
        if ($plan === []) {
            return null;
        }

        return $this->zones->change($zone, function (DnsZone $zone) use ($plan) {
            foreach ($plan as [$w, $replace]) {
                $zone->records()->whereKey($replace)->delete();
                $zone->records()->create([
                    'name' => $this->relative($w['name'], $zone),
                    'type' => $w['type'],
                    'content' => $w['content'],
                    'priority' => $w['priority'],
                    'ttl' => $zone->default_ttl,
                ]);
            }
        });
    }

    /**
     * Remove the records Serfex wrote for the domain, leaving any the user
     * changed. Returns the zone's undo token, or null.
     */
    public function remove(MailDomain $domain): ?string
    {
        $zone = $this->zoneFor($domain);
        if ($zone === null) {
            return null;
        }
        $records = $zone->records()->get();
        $ids = [];
        foreach ($this->wanted($domain) as $w) {
            $match = $this->candidates($w, $records, $zone)->first(fn (DnsRecord $r) => $this->equals($w, $r));
            if ($match !== null) {
                $ids[] = $match->id;
            }
        }
        if ($ids === []) {
            return null;
        }

        return $this->zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($ids)->delete());
    }

    /**
     * Each wanted record with its state in the zone and, when $public, in
     * public DNS: written, propagated, missing or different.
     *
     * @return list<array<string, mixed>>
     */
    public function check(MailDomain $domain, bool $public = true): array
    {
        $zone = $this->zoneFor($domain);
        $records = $zone?->records()->get() ?? collect();
        $out = [];
        foreach ($this->wanted($domain) as $w) {
            $state = $zone === null ? ['state' => 'nozone', 'others' => collect()] : $this->zoneState($w, $records, $zone);
            $found = $public ? $this->public->lookup($w['name'], $w['type']) : null;
            $live = $found === null ? null : in_array($this->display($w), $this->normalize($found, $w), true);
            $out[] = [
                ...$w,
                'value' => $this->display($w),
                'zone' => $state['state'],
                'current' => $state['others']->map(fn (DnsRecord $r) => trim(($r->priority !== null ? $r->priority.' ' : '').$r->content))->values()->all(),
                'public' => $live,
                'badge' => $this->badge($state['state'], $live),
            ];
        }

        return $out;
    }

    /**
     * Forget cached public answers for the domain's records.
     */
    public function recheck(MailDomain $domain): void
    {
        $this->public->forget(array_map(fn ($w) => [$w['name'], $w['type']], $this->wanted($domain)));
    }

    /**
     * One badge for lists: are the records in the zone?
     */
    public function summary(MailDomain $domain): Badge
    {
        $rows = $this->check($domain, public: false);
        if ($rows !== [] && $rows[0]['zone'] === 'nozone') {
            return new Badge(__('DNS başka yerde'), 'muted');
        }
        $missing = count(array_filter($rows, fn ($r) => $r['zone'] === 'missing'));
        $different = count(array_filter($rows, fn ($r) => $r['zone'] === 'different'));

        return match (true) {
            $missing > 0 => new Badge(__(':missing kayıt eksik', ['missing' => $missing]), 'crit'),
            $different > 0 => new Badge(__(':different kayıt farklı', ['different' => $different]), 'warn'),
            default => new Badge(__('Kayıtlar tamam'), 'ok'),
        };
    }

    private function badge(string $zone, ?bool $live): Badge
    {
        return match (true) {
            $live === true => new Badge(__('Yayıldı'), 'ok'),
            $zone === 'written' => new Badge(__('Yazıldı'), 'info'),
            $zone === 'different' => new Badge(__('Farklı'), 'warn'),
            $zone === 'nozone' => new Badge(__('Eksik'), 'warn'),
            default => new Badge(__('Eksik'), 'crit'),
        };
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  Collection<int, DnsRecord>  $records
     * @return array{state: string, others: Collection<int, DnsRecord>}
     */
    private function zoneState(array $w, Collection $records, DnsZone $zone): array
    {
        $candidates = $this->candidates($w, $records, $zone);
        if ($candidates->contains(fn (DnsRecord $r) => $this->equals($w, $r))) {
            return ['state' => 'written', 'others' => collect()];
        }

        return ['state' => $candidates->isEmpty() ? 'missing' : 'different', 'others' => $candidates->values()];
    }

    /**
     * Records in the zone that the wanted record would replace.
     *
     * @param  array<string, mixed>  $w
     * @param  Collection<int, DnsRecord>  $records
     * @return Collection<int, DnsRecord>
     */
    private function candidates(array $w, Collection $records, DnsZone $zone): Collection
    {
        $name = $this->relative($w['name'], $zone);
        $at = $records->where('name', $name);

        return match ($w['policy']) {
            'name' => $at,
            'prefix' => $at->where('type', 'TXT')->filter(fn (DnsRecord $r) => stripos(ltrim($r->content), $w['prefix']) === 0),
            default => $at->where('type', $w['type']),
        };
    }

    /**
     * @param  array<string, mixed>  $w
     */
    private function equals(array $w, DnsRecord $r): bool
    {
        return $r->type === $w['type'] && $r->content === $w['content']
            && (! in_array($w['type'], ['MX', 'SRV'], true) || (int) $r->priority === (int) $w['priority']);
    }

    /**
     * @param  array<string, mixed>  $w
     */
    private function display(array $w): string
    {
        return $w['priority'] !== null ? $w['priority'].' '.$w['content'] : $w['content'];
    }

    /**
     * @param  list<string>  $found
     * @param  array<string, mixed>  $w
     * @return list<string>
     */
    private function normalize(array $found, array $w): array
    {
        return $w['type'] === 'TXT' ? $found : array_map('strtolower', $found);
    }

    /**
     * An absolute name relative to the zone; "@" for the apex.
     */
    private function relative(string $name, DnsZone $zone): string
    {
        return $name === $zone->name ? '@' : substr($name, 0, -strlen('.'.$zone->name));
    }
}
