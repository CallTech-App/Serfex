<?php

namespace App\Services\Cloudflare;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Models\CloudflareAccount;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\ZoneTemplate;
use App\Support\DnsRecords;
use Illuminate\Support\Facades\DB;

/**
 * Puts zones at Cloudflare and takes them back: finding a domain in the
 * connected accounts, comparing its records there with the panel's, and
 * connecting a zone with the choices made.
 */
class CloudflareZones
{
    /** Ways a difference can be settled, by kind of row. */
    public const CHOICES = [
        'differ' => ['serfex', 'cloudflare'],
        'cloudflare' => ['import', 'delete'],
        'serfex' => ['push', 'drop'],
    ];

    public function __construct(
        private readonly DnsSettings $dns,
        private readonly ZoneTemplate $template,
        private readonly CloudflareSettings $settings,
    ) {}

    /**
     * Check a token and fill in what Cloudflare says about it.
     *
     * @throws CloudflareException
     */
    public function check(CloudflareAccount $account): CloudflareAccount
    {
        $api = CloudflareApi::for($account->token);
        try {
            $status = $api->verify();
            if ($status !== 'active') {
                throw new CloudflareException(__('Anahtar etkin değil (:status).', ['status' => $status ?: '?']));
            }
            $accounts = $api->accounts();
            $account->fill([
                'account_id' => $accounts[0]['id'] ?? null,
                'account_name' => $accounts[0]['name'] ?? null,
                'zones_count' => $api->zoneCount(),
                'status' => CloudflareAccount::OK,
                'last_error' => null,
                'checked_at' => now(),
            ]);
        } catch (CloudflareException $e) {
            $account->fill(['status' => CloudflareAccount::INVALID, 'last_error' => $e->getMessage(), 'checked_at' => now()]);
            if ($account->exists) {
                $account->save();
            }

            throw $e;
        }
        if ($account->exists) {
            $account->save();
        }

        return $account;
    }

    /**
     * The connected account and Cloudflare zone that hold $domain (its
     * own zone or a parent's), or null.
     *
     * @return array{account: CloudflareAccount, zone: array<string, mixed>}|null
     */
    public function find(string $domain): ?array
    {
        $names = [];
        $labels = explode('.', $domain);
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $names[] = implode('.', array_slice($labels, $i));
        }
        foreach (CloudflareAccount::where('status', CloudflareAccount::OK)->orderBy('id')->get() as $account) {
            $api = CloudflareApi::for($account->token);
            foreach ($names as $name) {
                try {
                    $zones = $api->zones($name);
                } catch (CloudflareException) {
                    continue 2;
                }
                foreach ($zones as $z) {
                    if (($z['name'] ?? null) === $name) {
                        return ['account' => $account, 'zone' => $z];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Add $domain to the account at Cloudflare (needs Zone → Zone → Edit).
     *
     * @return array<string, mixed>
     *
     * @throws CloudflareException
     */
    public function create(CloudflareAccount $account, string $domain): array
    {
        if ($account->account_id === null) {
            throw new CloudflareException(__('Bu anahtar bir Cloudflare hesabı göstermiyor; alan adını Cloudflare\'de ekleyin.'));
        }

        return CloudflareApi::for($account->token)->createZone($account->account_id, $domain);
    }

    /**
     * The records a new site needs in its zone at Cloudflare: its address
     * records (Serfex's win over what is there) and the template's other
     * rows where Cloudflare has nothing at that name.
     *
     * @return list<array{name: string, type: string, content: string, priority: ?int, ttl: int}>
     */
    public function siteRecords(Site $site, string $zoneName): array
    {
        $label = $site->domain === $zoneName ? '@' : substr($site->domain, 0, -strlen('.'.$zoneName));
        $ttl = $this->dns->defaultTtl();
        $this->template->detectAddresses();
        $out = [];
        foreach (['A' => $this->dns->ipv4(), 'AAAA' => $this->dns->ipv6()] as $type => $ip) {
            if ($ip !== null) {
                $out[] = ['name' => $label, 'type' => $type, 'content' => $ip, 'priority' => null, 'ttl' => $ttl];
            }
        }
        if (in_array('www.'.$site->domain, $site->aliases ?? [], true)) {
            $out[] = ['name' => $label === '@' ? 'www' : 'www.'.$label, 'type' => 'CNAME', 'content' => $site->domain.'.', 'priority' => null, 'ttl' => $ttl];
        }

        return $out;
    }

    /**
     * Compare the panel's records with Cloudflare's. Each row is one name
     * (and kind of record) where the two differ, with the choice made by
     * default; $siteNames are the names a site is being created for, where
     * the panel's address records win.
     *
     * @param  list<array{name: string, type: string, content: string, priority: ?int, ttl?: int, id?: int}>  $panel
     * @param  list<array<string, mixed>>  $cloudflare  Cloudflare's records as its API returns them
     * @param  list<string>|null  $siteNames  relative names; null when connecting an existing zone
     * @return array{same: int, rows: list<array{key: string, kind: string, name: string, type: string, cloudflare: list<string>, serfex: list<string>, choice: string}>}
     */
    public function compare(string $zone, int $defaultTtl, array $panel, array $cloudflare, ?array $siteNames = null): array
    {
        $theirs = [];
        foreach ($cloudflare as $cf) {
            $rec = CloudflareRecords::fromCloudflare($cf, $zone, $defaultTtl);
            if ($rec !== null) {
                $theirs[] = $rec + ['cf_id' => (string) ($cf['id'] ?? '')];
            }
        }
        $ours = array_values(array_filter($panel, fn ($r) => ! ($r['type'] === 'NS' && $r['name'] === '@')));

        // Same on both sides.
        $same = 0;
        foreach ($ours as $i => $o) {
            foreach ($theirs as $j => $t) {
                if (CloudflareRecords::identity($o) === CloudflareRecords::identity($t)) {
                    unset($ours[$i], $theirs[$j]);
                    $same++;
                    break;
                }
            }
        }

        $groups = [];
        foreach ($ours as $o) {
            $groups[self::group($o)]['serfex'][] = $o;
        }
        foreach ($theirs as $t) {
            $groups[self::group($t)]['cloudflare'][] = $t;
        }
        ksort($groups);
        $rows = [];
        foreach ($groups as $key => $g) {
            $s = $g['serfex'] ?? [];
            $c = $g['cloudflare'] ?? [];
            $first = $s[0] ?? $c[0];
            $kind = $s !== [] && $c !== [] ? 'differ' : ($s !== [] ? 'serfex' : 'cloudflare');
            $choice = match ($kind) {
                'differ' => $siteNames === null || (in_array($first['name'], $siteNames, true) && in_array($first['type'], DnsRecord::PROXIABLE, true)) ? 'serfex' : 'cloudflare',
                'serfex' => 'push',
                default => 'import',
            };
            $types = array_values(array_unique(array_map(fn ($r) => $r['type'], [...$s, ...$c])));
            $rows[] = [
                'key' => md5($key),
                'kind' => $kind,
                'name' => $first['name'],
                'type' => implode('/', $types),
                'cloudflare' => array_map(fn ($r) => self::display($r, count($types) > 1), $c),
                'serfex' => array_map(fn ($r) => self::display($r, count($types) > 1), $s),
                'choice' => $choice,
                'group' => $key,
            ];
        }

        return ['same' => $same, 'rows' => $rows];
    }

    /**
     * Records at Cloudflare a new site would replace (what "Site ekle"
     * shows before the site is created).
     *
     * @return array{zone: string, changes: list<array{name: string, type: string, from: list<string>, to: list<string>}>, kept: int}
     *
     * @throws CloudflareException
     */
    public function preview(CloudflareAccount $account, array $cfZone, string $domain, bool $www): array
    {
        $site = new Site(['domain' => $domain, 'aliases' => $www ? ['www.'.$domain] : []]);
        $zoneName = (string) $cfZone['name'];
        $remote = CloudflareApi::for($account->token)->records((string) $cfZone['id']);
        $panel = $this->siteRecords($site, $zoneName);
        $names = array_values(array_unique(array_column($panel, 'name')));
        $plan = $this->compare($zoneName, $this->dns->defaultTtl(), $panel, $remote, $names);
        $changes = [];
        foreach ($plan['rows'] as $row) {
            if ($row['kind'] === 'differ' && $row['choice'] === 'serfex') {
                $changes[] = ['name' => DnsRecords::fqdn($row['name'], $zoneName), 'type' => $row['type'], 'from' => $row['cloudflare'], 'to' => $row['serfex']];
            } elseif ($row['kind'] === 'serfex') {
                $changes[] = ['name' => DnsRecords::fqdn($row['name'], $zoneName), 'type' => $row['type'], 'from' => [], 'to' => $row['serfex']];
            }
        }
        $kept = count(array_filter($remote, fn ($cf) => CloudflareRecords::fromCloudflare($cf, $zoneName, 3600) !== null)) - array_sum(array_map(fn ($c) => count($c['from']), $changes));

        return ['zone' => $zoneName, 'changes' => $changes, 'kept' => max(0, $kept)];
    }

    /**
     * Give a new site its DNS at Cloudflare: the panel's zone for the
     * Cloudflare zone holds Cloudflare's records, with the site's address
     * records in place of any there. $proxied picks the proxy for them.
     *
     * @param  array<string, mixed>  $cfZone
     *
     * @throws CloudflareException
     */
    public function attachSite(Site $site, CloudflareAccount $account, array $cfZone, bool $proxied): DnsZone
    {
        $zoneName = (string) $cfZone['name'];
        $remote = CloudflareApi::for($account->token)->records((string) $cfZone['id']);
        $zone = DnsZone::where('name', $zoneName)->first();
        $panel = $this->siteRecords($site, $zoneName);
        if ($zone !== null) {
            $panel = [...$zone->records->map(fn (DnsRecord $r) => $r->toRecord())->all(), ...$panel];
        }
        $names = array_values(array_unique(array_column($this->siteRecords($site, $zoneName), 'name')));
        $plan = $this->compare($zoneName, $this->dns->defaultTtl(), $panel, $remote, $names);
        $choices = array_column($plan['rows'], 'choice', 'key');

        $zone ??= new DnsZone([
            'name' => $zoneName,
            'site_id' => $site->domain === $zoneName ? $site->id : null,
            'hostmaster' => $this->dns->hostmaster(),
            'default_ttl' => $this->dns->defaultTtl(),
            ...$this->dns->soa(),
        ]);
        $siteNames = array_flip($names);
        $this->connect($zone, $account, $cfZone, $remote, $panel, $choices, function (array $r) use ($siteNames, $proxied) {
            return isset($siteNames[$r['name']]) && in_array($r['type'], DnsRecord::PROXIABLE, true) ? $proxied : null;
        });

        return $zone;
    }

    /**
     * Connect an existing zone to Cloudflare with the choices made on the
     * comparison (row key => choice); rows without a choice keep the
     * default.
     *
     * @param  array<string, mixed>  $cfZone
     * @param  array<string, string>  $choices
     *
     * @throws CloudflareException
     */
    public function attach(DnsZone $zone, CloudflareAccount $account, array $cfZone, array $choices): void
    {
        $remote = CloudflareApi::for($account->token)->records((string) $cfZone['id']);
        $panel = $zone->records()->get()->map(fn (DnsRecord $r) => $r->toRecord())->all();
        $plan = $this->compare($zone->name, $zone->default_ttl, $panel, $remote);
        $picked = [];
        foreach ($plan['rows'] as $row) {
            $c = $choices[$row['key']] ?? $row['choice'];
            $picked[$row['key']] = in_array($c, self::CHOICES[$row['kind']], true) ? $c : $row['choice'];
        }
        $this->connect($zone, $account, $cfZone, $remote, $panel, $picked, fn () => null);
    }

    /**
     * Stop using Cloudflare for the zone: this server's DNS serves it again
     * with the panel's records. Nothing is changed at Cloudflare.
     */
    public function detach(DnsZone $zone): void
    {
        DB::transaction(function () use ($zone) {
            if ($zone->records()->where('name', '@')->where('type', 'NS')->doesntExist()) {
                foreach ($this->template->records($zone->name, true) as $r) {
                    if ($zone->records()->where('name', $r['name'])->where('type', $r['type'])->where('content', $r['content'])->doesntExist()) {
                        $zone->records()->create($r);
                    }
                }
            }
            $zone->records()->update(['cloudflare_id' => null, 'cloudflare_hash' => null, 'cloudflare_modified_at' => null]);
            $zone->update([
                'cloudflare_account_id' => null,
                'cloudflare_zone_id' => null,
                'cloudflare_info' => null,
                'cloudflare_known_ids' => null,
                'cloudflare_synced_at' => null,
                'status' => ZoneStatus::Syncing,
                'last_error' => null,
            ]);
        });
        SyncDnsZone::dispatch($zone);
    }

    /**
     * Turn Cloudflare's DNSSEC on or off for the zone.
     *
     * @throws CloudflareException
     */
    public function setDnssec(DnsZone $zone, bool $on): void
    {
        $res = CloudflareApi::for($zone->cloudflareAccount->token)->setDnssec((string) $zone->cloudflare_zone_id, $on);
        $info = $zone->cloudflare_info ?? [];
        $info['dnssec'] = ['status' => $res['status'] ?? ($on ? 'pending' : 'disabled'), 'ds' => $res['ds'] ?? null];
        $zone->forceFill(['cloudflare_info' => $info])->saveQuietly();
    }

    /**
     * Set the zone's SSL/TLS mode.
     *
     * @throws CloudflareException
     */
    public function setSsl(DnsZone $zone, string $mode): void
    {
        CloudflareApi::for($zone->cloudflareAccount->token)->setSslMode((string) $zone->cloudflare_zone_id, $mode);
        $info = $zone->cloudflare_info ?? [];
        $info['ssl'] = $mode;
        unset($info['ssl_error']);
        $zone->forceFill(['cloudflare_info' => $info])->saveQuietly();
    }

    /**
     * Purge the site's pages from Cloudflare's cache. A zone holding only
     * this site is purged whole.
     *
     * @throws CloudflareException
     */
    public function purge(Site $site): void
    {
        $zone = $this->zoneOf($site) ?? throw new CloudflareException(__('Bu sitenin DNS\'i Cloudflare\'de değil.'));
        $others = Site::whereKeyNot($site->id)->where(fn ($q) => $q->where('domain', $zone->name)->orWhere('domain', 'like', '%.'.$zone->name))->exists();
        CloudflareApi::for($zone->cloudflareAccount->token)->purge((string) $zone->cloudflare_zone_id, $others ? $site->hostnames() : []);
    }

    /**
     * The Cloudflare zone holding the site's domain, if its DNS is there.
     */
    public function zoneOf(Site $site): ?DnsZone
    {
        $labels = explode('.', $site->domain);
        $names = [];
        for ($i = 0; $i < count($labels) - 1; $i++) {
            $names[] = implode('.', array_slice($labels, $i));
        }

        return DnsZone::with('cloudflareAccount')->whereIn('name', $names)->whereNotNull('cloudflare_zone_id')->whereNotNull('cloudflare_account_id')
            ->orderByRaw('length(name) desc')->first();
    }

    /**
     * Write the zone's records from the comparison's choices and switch it
     * to Cloudflare; the sync that follows writes what differs.
     *
     * @param  array<string, mixed>  $cfZone
     * @param  list<array<string, mixed>>  $remote
     * @param  list<array<string, mixed>>  $panel
     * @param  array<string, string>  $choices  row key => choice
     * @param  callable(array<string, mixed>): ?bool  $proxy  the proxy for a record Serfex adds
     */
    private function connect(DnsZone $zone, CloudflareAccount $account, array $cfZone, array $remote, array $panel, array $choices, callable $proxy): void
    {
        $ttl = $zone->default_ttl ?? $this->dns->defaultTtl();
        $byGroup = [];
        $same = [];
        $theirs = [];
        foreach ($remote as $cf) {
            $rec = CloudflareRecords::fromCloudflare($cf, $zone->name, $ttl);
            if ($rec !== null) {
                $theirs[] = ['rec' => $rec, 'cf' => $cf];
            }
        }
        $ours = array_values(array_filter($panel, fn ($r) => ! ($r['type'] === 'NS' && $r['name'] === '@')));
        foreach ($ours as $i => $o) {
            foreach ($theirs as $j => $t) {
                if (CloudflareRecords::identity($o) === CloudflareRecords::identity($t['rec'])) {
                    $same[] = $t;
                    unset($ours[$i], $theirs[$j]);
                    break;
                }
            }
        }
        foreach ($ours as $o) {
            $byGroup[md5(self::group($o))]['serfex'][] = $o;
        }
        foreach ($theirs as $t) {
            $byGroup[md5(self::group($t['rec']))]['cloudflare'][] = $t;
        }

        // The records the zone ends up with: Cloudflare's (with their ids)
        // where Cloudflare's are kept, the panel's where they win.
        $final = [];
        foreach ($same as $t) {
            $final[] = ['rec' => $t['rec'], 'cf' => $t['cf']];
        }
        foreach ($byGroup as $key => $g) {
            $choice = $choices[$key] ?? null;
            $keepTheirs = in_array($choice, ['cloudflare', 'import'], true) || ($choice === null && ! isset($g['serfex']));
            $keepOurs = in_array($choice, ['serfex', 'push'], true) || ($choice === null && ! isset($g['cloudflare']));
            if ($keepTheirs) {
                foreach ($g['cloudflare'] ?? [] as $t) {
                    $final[] = ['rec' => $t['rec'], 'cf' => $t['cf']];
                }
            }
            if ($keepOurs) {
                foreach ($g['serfex'] ?? [] as $o) {
                    $final[] = ['rec' => $o, 'cf' => null];
                }
            }
        }

        DB::transaction(function () use ($zone, $account, $cfZone, $remote, $final, $proxy) {
            $zone->fill([
                'cloudflare_account_id' => $account->id,
                'cloudflare_zone_id' => (string) $cfZone['id'],
                'cloudflare_info' => [
                    'status' => $cfZone['status'] ?? null,
                    'name_servers' => array_values($cfZone['name_servers'] ?? []),
                    'original_name_servers' => array_values($cfZone['original_name_servers'] ?? []),
                    'account_name' => $cfZone['account']['name'] ?? $account->account_name,
                ],
                // Every record Cloudflare has now is known: one the zone
                // does not keep is deleted there by the sync.
                'cloudflare_known_ids' => array_values(array_filter(array_map(fn ($cf) => isset($cf['id']) && CloudflareRecords::fromCloudflare($cf, $zone->name, 3600) !== null ? (string) $cf['id'] : null, $remote))),
                'cloudflare_synced_at' => null,
                'status' => ZoneStatus::Syncing,
                'last_error' => null,
            ])->save();
            $zone->records()->delete();
            foreach ($final as $f) {
                $r = $zone->records()->make([
                    'name' => $f['rec']['name'],
                    'type' => $f['rec']['type'],
                    'content' => $f['rec']['content'],
                    'priority' => $f['rec']['priority'],
                    'ttl' => $f['rec']['ttl'] ?? $zone->default_ttl,
                    'proxied' => $f['cf'] !== null ? $f['rec']['proxied'] : $proxy($f['rec']),
                ]);
                if ($f['cf'] !== null) {
                    $r->cloudflare_id = (string) $f['cf']['id'];
                    $r->cloudflare_modified_at = isset($f['cf']['modified_on']) ? \Illuminate\Support\Carbon::parse($f['cf']['modified_on']) : now();
                    $r->cloudflare_hash = CloudflareRecords::hash($r);
                }
                $r->save();
            }
        });
        $zone->unsetRelation('records');
        SyncDnsZone::dispatch($zone);
    }

    /**
     * What a record is compared under: address records (A, AAAA, CNAME) of
     * a name together, SPF, DMARC and DKIM by their kind, other TXT records
     * one by one, everything else by name and type.
     *
     * @param  array{name: string, type: string, content: string}  $r
     */
    private static function group(array $r): string
    {
        if (in_array($r['type'], DnsRecord::PROXIABLE, true)) {
            return $r['name'].'|ADDR';
        }
        if ($r['type'] === 'TXT') {
            foreach (['v=spf1', 'v=DMARC1', 'v=DKIM1'] as $prefix) {
                if (stripos($r['content'], $prefix) === 0) {
                    return $r['name'].'|TXT|'.$prefix;
                }
            }

            return $r['name'].'|TXT|'.$r['content'];
        }

        return $r['name'].'|'.$r['type'];
    }

    /**
     * @param  array{type: string, content: string, priority: ?int}  $r
     */
    private static function display(array $r, bool $withType): string
    {
        $value = ($r['priority'] !== null ? $r['priority'].' ' : '').rtrim($r['content'], '.');

        return $withType ? $r['type'].' '.$value : $value;
    }
}
