<?php

namespace App\Services\Dns;

use App\Enums\ZoneStatus;
use App\Jobs\DeleteDnsZone;
use App\Jobs\SyncDnsZone;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates, changes and removes zones in the panel and queues the agent work
 * that makes PowerDNS follow.
 */
class Zones
{
    /** How long an Undo stays possible, in seconds. */
    public const UNDO_SECONDS = 600;

    public function __construct(
        private readonly ZoneTemplate $template,
        private readonly DnsSettings $settings,
    ) {}

    /**
     * Create a zone with the template's records (or only NS records).
     */
    public function create(string $name, ?Site $site = null, bool $empty = false): DnsZone
    {
        $records = $this->template->records($name, $empty);

        $zone = DB::transaction(function () use ($name, $site, $records) {
            $zone = DnsZone::create([
                'name' => $name,
                'site_id' => $site?->id,
                'status' => ZoneStatus::Syncing,
                'hostmaster' => $this->settings->hostmaster(),
                'default_ttl' => $this->settings->defaultTtl(),
                ...$this->settings->soa(),
            ]);
            $zone->records()->createMany($records);

            return $zone;
        });
        SyncDnsZone::dispatch($zone);

        return $zone;
    }

    /**
     * Give a new site its DNS: its own zone, or address records in the zone
     * of its parent domain when that is hosted here (blog.example.com in
     * example.com). Returns the zone that was created, if any.
     */
    public function forSite(Site $site): ?DnsZone
    {
        $existing = DnsZone::where('name', $site->domain)->first();
        if ($existing !== null) {
            if ($existing->site_id === null) {
                $existing->update(['site_id' => $site->id]);
            }

            return null;
        }

        $parent = $this->parentZone($site->domain);
        if ($parent === null) {
            return $this->create($site->domain, $site);
        }

        $this->template->detectAddresses();
        $label = substr($site->domain, 0, -strlen('.'.$parent->name));
        $wanted = [];
        foreach (['A' => $this->settings->ipv4(), 'AAAA' => $this->settings->ipv6()] as $type => $ip) {
            if ($ip !== null) {
                $wanted[] = ['name' => $label, 'type' => $type, 'content' => $ip];
            }
        }
        foreach ($site->aliases ?? [] as $alias) {
            if ($alias === 'www.'.$site->domain) {
                $wanted[] = ['name' => 'www.'.$label, 'type' => 'CNAME', 'content' => $site->domain.'.'];
            }
        }
        $this->change($parent, function (DnsZone $zone) use ($wanted) {
            foreach ($wanted as $w) {
                if ($zone->records()->where('name', $w['name'])->where('type', $w['type'])->doesntExist()
                    && ($w['type'] !== 'CNAME' || $zone->records()->where('name', $w['name'])->doesntExist())) {
                    $zone->records()->create([...$w, 'ttl' => $zone->default_ttl]);
                }
            }
        });

        return null;
    }

    /**
     * The zone on this server that holds $name: its own or a parent's.
     */
    public function holding(string $name): ?DnsZone
    {
        $zone = DnsZone::where('name', $name)->first() ?? $this->parentZone($name);

        return $zone !== null && $zone->status !== ZoneStatus::Deleting ? $zone : null;
    }

    /**
     * Point a name the site answers to (an alias, a subdomain, a
     * redirected domain) at this server: address records in the zone that
     * holds it, unless it has records already. With $create, a name no zone
     * here holds gets a zone of its own for the site. Returns the zone, or
     * null when its DNS is elsewhere.
     */
    public function addHost(string $name, Site $site, bool $create = false): ?DnsZone
    {
        $zone = $this->holding($name);
        if ($zone === null) {
            return $create ? $this->create($name, $site) : null;
        }
        $this->template->detectAddresses();
        $label = $zone->name === $name ? '@' : substr($name, 0, -strlen('.'.$zone->name));
        if ($zone->records()->where('name', $label)->whereIn('type', ['A', 'AAAA', 'CNAME'])->exists()) {
            return $zone;
        }
        $wanted = [];
        foreach (['A' => $this->settings->ipv4(), 'AAAA' => $this->settings->ipv6()] as $type => $ip) {
            if ($ip !== null) {
                $wanted[] = ['name' => $label, 'type' => $type, 'content' => $ip, 'ttl' => $zone->default_ttl];
            }
        }
        if ($wanted !== []) {
            $this->change($zone, fn (DnsZone $zone) => $zone->records()->createMany($wanted));
        }

        return $zone;
    }

    /**
     * Take away the address records addHost() wrote for $name: A and AAAA
     * records pointing at this server. A zone of its own stays; it may
     * hold mail and other records. Returns an undo token, or null when
     * nothing changed.
     */
    public function removeHost(string $name): ?string
    {
        $zone = $this->holding($name);
        if ($zone === null || $zone->name === $name) {
            return null;
        }
        $label = substr($name, 0, -strlen('.'.$zone->name));
        $ours = $zone->records()->where('name', $label)->whereIn('type', ['A', 'AAAA'])
            ->whereIn('content', array_filter([$this->settings->ipv4(), $this->settings->ipv6()]));
        if ($ours->doesntExist()) {
            return null;
        }

        return $this->change($zone, fn () => (clone $ours)->delete());
    }

    /**
     * The closest hosted zone that $domain is a subdomain of.
     */
    public function parentZone(string $domain): ?DnsZone
    {
        $labels = explode('.', $domain);
        $candidates = [];
        for ($i = 1; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates === [] ? null : DnsZone::whereIn('name', $candidates)
            ->orderByRaw('length(name) desc')
            ->first();
    }

    /**
     * Run $change on the zone's records in a transaction, then send the
     * zone to the agent. Returns an undo token restoring the previous
     * records and settings.
     *
     * @param  callable(DnsZone): void  $change
     */
    public function change(DnsZone $zone, callable $change): string
    {
        $token = $this->remember($zone);
        DB::transaction(function () use ($zone, $change) {
            $change($zone);
            $zone->update(['status' => ZoneStatus::Syncing, 'last_error' => null]);
        });
        $zone->unsetRelation('records');
        SyncDnsZone::dispatch($zone);

        return $token;
    }

    /**
     * Replace all records of a zone.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function replaceRecords(DnsZone $zone, array $records): string
    {
        return $this->change($zone, function (DnsZone $zone) use ($records) {
            $zone->records()->delete();
            $zone->records()->createMany($records);
        });
    }

    /**
     * Put the zone back to the template's records.
     */
    public function reset(DnsZone $zone): string
    {
        return $this->replaceRecords($zone, $this->template->records($zone->name));
    }

    /**
     * Restore what an undo token remembered. A deleted zone is recreated.
     * Returns the zone, or null when the token expired.
     */
    public function undo(string $token): ?DnsZone
    {
        $saved = Cache::pull('dns-undo:'.$token);
        if ($saved === null) {
            return null;
        }
        // The signing key was remembered still encrypted.
        if (($saved['dnssec_key'] ?? null) !== null) {
            $saved['zone']['dnssec_key'] = Crypt::decryptString($saved['dnssec_key']);
        }
        if ($saved['zone']['site_id'] !== null && ! Site::whereKey($saved['zone']['site_id'])->exists()) {
            $saved['zone']['site_id'] = null;
        }
        $zone = DnsZone::find($saved['id']);
        if ($zone === null || $zone->status === ZoneStatus::Deleting) {
            if (DnsZone::where('name', $saved['zone']['name'])->whereKeyNot($saved['id'])->exists()) {
                return null;
            }
            $zone ??= new DnsZone;
            $zone->fill([...$saved['zone'], 'status' => ZoneStatus::Syncing, 'last_error' => null]);
            if (! $zone->exists) {
                $zone->id = $saved['id'];
            }
            DB::transaction(function () use ($zone, $saved) {
                $zone->save();
                $zone->records()->delete();
                $zone->records()->createMany($saved['records']);
            });
            SyncDnsZone::dispatch($zone);

            return $zone;
        }

        $this->change($zone, function (DnsZone $zone) use ($saved) {
            $zone->update($saved['zone']);
            $zone->records()->delete();
            $zone->records()->createMany($saved['records']);
        });

        return $zone;
    }

    /**
     * Mark zones for deletion and queue their removal from PowerDNS.
     * Returns an undo token per zone.
     *
     * @return array<int, string>
     */
    public function delete(DnsZone ...$zones): array
    {
        $tokens = [];
        foreach ($zones as $zone) {
            if ($zone->status === ZoneStatus::Deleting) {
                continue;
            }
            $tokens[$zone->id] = $this->remember($zone);
            $zone->update(['status' => ZoneStatus::Deleting]);
            DeleteDnsZone::dispatch($zone);
        }

        return $tokens;
    }

    /**
     * Save the zone's settings and records for an Undo.
     */
    private function remember(DnsZone $zone): string
    {
        $token = Str::random(24);
        Cache::put('dns-undo:'.$token, [
            'id' => $zone->id,
            'zone' => $zone->only(['name', 'site_id', 'hostmaster', 'default_ttl', 'refresh', 'retry', 'expire', 'minimum', 'dnssec']),
            'dnssec_key' => $zone->getRawOriginal('dnssec_key'),
            'records' => $zone->records()->get()->map(fn (DnsRecord $r) => $r->toRecord())->all(),
        ], self::UNDO_SECONDS);

        return $token;
    }
}
