<?php

namespace App\Services\Cloudflare;

use App\Enums\CertificateStatus;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Site;
use App\Support\DnsRecords;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a zone's records the same in the panel and at Cloudflare, both
 * ways.
 *
 * Each record remembers its Cloudflare id, what it was at the last sync
 * (a hash) and when Cloudflare last changed it. So a sync can tell:
 *
 *  - changed in the panel since → written to Cloudflare (the panel wins
 *    when both sides changed the same record);
 *  - changed only at Cloudflare → taken into the panel;
 *  - deleted in the panel (its id was known, it is gone here) → deleted
 *    at Cloudflare;
 *  - deleted at Cloudflare and not changed here → deleted here;
 *  - new at Cloudflare (an id never seen) → added here;
 *  - new in the panel → added at Cloudflare.
 *
 * Records of types the panel does not keep are left alone at Cloudflare.
 * Name server records at the apex are Cloudflare's and are never written.
 */
class CloudflareSync
{
    public function __construct(private readonly CloudflareSettings $settings) {}

    /**
     * Sync the records and refresh what the zone shows of Cloudflare's
     * state. Returns counts of what was done; record errors (a write
     * Cloudflare refused) are collected and thrown together at the end, so
     * one bad record does not stop the others.
     *
     * @return array{created: int, updated: int, deleted: int, imported: int, removed: int}
     *
     * @throws CloudflareException
     */
    public function sync(DnsZone $zone): array
    {
        $account = $zone->cloudflareAccount ?? throw new CloudflareException(__('Cloudflare hesabı bulunamadı.'));
        $api = CloudflareApi::for($account->token);
        $zoneId = (string) $zone->cloudflare_zone_id;

        $this->refreshInfo($zone, $api);

        $remote = [];
        foreach ($api->records($zoneId) as $cf) {
            $rec = CloudflareRecords::fromCloudflare($cf, $zone->name, $zone->default_ttl);
            if ($rec !== null && isset($cf['id'])) {
                $remote[(string) $cf['id']] = ['cf' => $cf, 'rec' => $rec, 'modified' => self::modified($cf)];
            }
        }
        $known = array_flip($zone->cloudflare_known_ids ?? []);
        $records = $zone->records()->orderBy('id')->get()->reject(fn (DnsRecord $r) => ! self::synced($r));
        $this->decideProxied($zone, $records);

        // Records written again in the panel (an Undo, a reset) lost their
        // id: find them by what they are.
        $claimed = $records->pluck('cloudflare_id')->filter()->flip()->all();
        foreach ($records->whereNull('cloudflare_id') as $r) {
            foreach ($remote as $id => $x) {
                if (! isset($claimed[$id]) && CloudflareRecords::identity($x['rec']) === CloudflareRecords::identity($r)) {
                    $r->cloudflare_id = $id;
                    $r->proxied ??= $x['rec']['proxied'];
                    $claimed[$id] = true;
                    break;
                }
            }
        }

        $done = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'imported' => 0, 'removed' => 0];
        $errors = [];
        foreach ($records as $r) {
            $id = $r->cloudflare_id;
            $dirty = $r->cloudflare_hash !== CloudflareRecords::hash($r);
            try {
                if ($id !== null && isset($remote[$id])) {
                    $x = $remote[$id];
                    $theirs = $r->cloudflare_modified_at !== null && $x['modified']->gt($r->cloudflare_modified_at);
                    if ($dirty && ! $this->same($r, $x['cf'], $zone)) {
                        $this->remember($r, $api->updateRecord($zoneId, $id, CloudflareRecords::toCloudflare($r, $zone->name)));
                        $done['updated']++;
                    } elseif (! $dirty && $theirs) {
                        $r->fill(array_intersect_key($x['rec'], array_flip(['name', 'type', 'content', 'priority', 'ttl', 'proxied'])));
                        $this->remember($r, $x['cf']);
                        $done['imported']++;
                    } else {
                        $this->remember($r, $x['cf']);
                    }
                } elseif ($id !== null && ! $dirty) {
                    // Deleted at Cloudflare.
                    $r->delete();
                    $done['removed']++;
                } else {
                    $this->remember($r, $api->createRecord($zoneId, CloudflareRecords::toCloudflare($r, $zone->name)));
                    $done['created']++;
                }
            } catch (CloudflareException $e) {
                if ($e->isAuthorization()) {
                    throw $e;
                }
                $errors[] = $r->type.' '.DnsRecords::fqdn($r->name, $zone->name).': '.$e->getMessage();
            }
        }

        // What is at Cloudflare and nowhere in the panel.
        $have = $zone->records()->whereNotNull('cloudflare_id')->pluck('cloudflare_id')->flip()->all();
        foreach ($remote as $id => $x) {
            if (isset($have[$id])) {
                continue;
            }
            if (isset($known[$id])) {
                // Deleted in the panel since the last sync.
                try {
                    $api->deleteRecord($zoneId, $id);
                    $done['deleted']++;
                } catch (CloudflareException $e) {
                    if ($e->isAuthorization()) {
                        throw $e;
                    }
                    $errors[] = $x['rec']['type'].' '.$x['cf']['name'].': '.$e->getMessage();
                    $have[$id] = true;
                }

                continue;
            }
            $r = $zone->records()->make(array_intersect_key($x['rec'], array_flip(['name', 'type', 'content', 'priority', 'ttl', 'proxied'])));
            $this->remember($r, $x['cf']);
            $have[$id] = true;
            $done['imported']++;
        }

        $ids = $zone->records()->whereNotNull('cloudflare_id')->pluck('cloudflare_id')->values()->all();
        $zone->forceFill(['cloudflare_known_ids' => $ids, 'cloudflare_synced_at' => now()])->saveQuietly();

        if ($errors !== []) {
            throw new CloudflareException(implode("\n", array_slice($errors, 0, 5)));
        }

        return $done;
    }

    /**
     * Read the zone's state at Cloudflare (pending or active, its name
     * servers, SSL mode, DNSSEC) and set the SSL mode to Full (strict)
     * once the site's certificate is in place.
     *
     * @throws CloudflareException
     */
    public function refreshInfo(DnsZone $zone, ?CloudflareApi $api = null): void
    {
        $api ??= CloudflareApi::for($zone->cloudflareAccount->token);
        $id = (string) $zone->cloudflare_zone_id;
        $cf = $api->zone($id);
        $info = $zone->cloudflare_info ?? [];
        $info['status'] = $cf['status'] ?? null;
        $info['name_servers'] = array_values($cf['name_servers'] ?? []);
        $info['original_name_servers'] = array_values($cf['original_name_servers'] ?? []);
        $info['account_name'] = $cf['account']['name'] ?? ($info['account_name'] ?? null);
        try {
            $info['ssl'] = $api->sslMode($id);
            unset($info['ssl_error']);
        } catch (CloudflareException $e) {
            $info['ssl'] = null;
            $info['ssl_error'] = $e->getMessage();
        }
        try {
            $dnssec = $api->dnssec($id);
            $info['dnssec'] = ['status' => $dnssec['status'] ?? null, 'ds' => $dnssec['ds'] ?? null];
        } catch (CloudflareException) {
            $info['dnssec'] = null;
        }

        if ($this->settings->strictSsl() && empty($info['ssl_set']) && $info['ssl'] !== null && $info['ssl'] !== 'strict' && $this->certificateReady($zone)) {
            try {
                $api->setSslMode($id, 'strict');
                $info['ssl'] = 'strict';
                $info['ssl_set'] = now()->toIso8601String();
            } catch (CloudflareException $e) {
                $info['ssl_error'] = $e->getMessage();
            }
        }
        $info['checked_at'] = now()->toIso8601String();
        $zone->forceFill(['cloudflare_info' => $info])->saveQuietly();
    }

    /**
     * Whether a site in the zone has a working certificate for its domain,
     * so Cloudflare's Full (strict) mode will not break it.
     */
    public function certificateReady(DnsZone $zone): bool
    {
        $sites = Site::with('certificate')->where('domain', $zone->name)->orWhere('domain', 'like', '%.'.$zone->name)->get();
        foreach ($sites as $site) {
            $c = $site->certificate;
            if ($c !== null && $c->status === CertificateStatus::Active && $c->isInstalled() && ! $c->isExpired()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Give records written by Serfex (no choice made yet) their proxy
     * setting: the names sites answer to go through the proxy when the
     * setting says so; everything else (mail, webmail, FTP, the panel)
     * never does.
     *
     * @param  Collection<int, DnsRecord>  $records
     */
    public function decideProxied(DnsZone $zone, Collection $records): void
    {
        $undecided = $records->filter(fn (DnsRecord $r) => $r->proxied === null && $r->canBeProxied());
        if ($undecided->isEmpty()) {
            return;
        }
        $hosts = $this->settings->proxySites() ? $this->siteHosts($zone) : [];
        foreach ($undecided as $r) {
            $r->proxied = isset($hosts[DnsRecords::fqdn($r->name, $zone->name)]);
            $r->saveQuietly();
        }
    }

    /**
     * The names sites answer to inside the zone.
     *
     * @return array<string, true>
     */
    public function siteHosts(DnsZone $zone): array
    {
        $hosts = [];
        $sites = Site::where('domain', $zone->name)->orWhere('domain', 'like', '%.'.$zone->name)->get();
        foreach ($sites as $site) {
            foreach ($site->hostnames() as $h) {
                if ($h === $zone->name || str_ends_with($h, '.'.$zone->name)) {
                    $hosts[$h] = true;
                }
            }
        }

        return $hosts;
    }

    /**
     * Whether a record is kept the same on both sides: Cloudflare's own
     * name server records are not.
     */
    public static function synced(DnsRecord $r): bool
    {
        return ! ($r->type === 'NS' && $r->name === '@');
    }

    /**
     * Note what Cloudflare now has for $r and save it.
     *
     * @param  array<string, mixed>  $cf
     */
    private function remember(DnsRecord $r, array $cf): void
    {
        DB::transaction(function () use ($r, $cf) {
            $r->cloudflare_id = (string) ($cf['id'] ?? $r->cloudflare_id);
            $r->cloudflare_modified_at = self::modified($cf);
            $r->cloudflare_hash = CloudflareRecords::hash($r);
            $r->saveQuietly();
        });
    }

    /**
     * Whether Cloudflare's copy already says what the panel's does (the
     * TTL of a proxied record is always Cloudflare's own).
     *
     * @param  array<string, mixed>  $cf
     */
    private function same(DnsRecord $r, array $cf, DnsZone $zone): bool
    {
        $theirs = CloudflareRecords::fromCloudflare($cf, $zone->name, $r->ttl);
        if ($theirs === null || CloudflareRecords::identity($theirs) !== CloudflareRecords::identity($r)) {
            return false;
        }
        if ($r->canBeProxied() && (bool) $theirs['proxied'] !== (bool) $r->proxied) {
            return false;
        }

        return ($r->canBeProxied() && $r->proxied) || $theirs['ttl'] === $r->ttl;
    }

    /**
     * @param  array<string, mixed>  $cf
     */
    private static function modified(array $cf): Carbon
    {
        return isset($cf['modified_on']) ? Carbon::parse($cf['modified_on']) : now();
    }
}
