<?php

namespace App\Models;

use App\Enums\ZoneStatus;
use App\Models\Concerns\OwnedThroughSite;
use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['dnssec_key'])]
#[Fillable(['site_id', 'name', 'status', 'hostmaster', 'default_ttl', 'refresh', 'retry', 'expire', 'minimum', 'dnssec', 'dnssec_key', 'dnssec_info', 'serial', 'last_error', 'synced_at',
    'cloudflare_account_id', 'cloudflare_zone_id', 'cloudflare_info', 'cloudflare_known_ids', 'cloudflare_synced_at'])]
class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory;

    use OwnedThroughSite;

    protected $attributes = [
        'status' => 'syncing',
        'dnssec' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => ZoneStatus::class,
            'synced_at' => 'datetime',
            'serial' => 'integer',
            'dnssec' => 'boolean',
            'dnssec_key' => 'encrypted',
            'dnssec_info' => 'array',
            'cloudflare_info' => 'array',
            'cloudflare_known_ids' => 'array',
            'cloudflare_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CloudflareAccount, $this>
     */
    public function cloudflareAccount(): BelongsTo
    {
        return $this->belongsTo(CloudflareAccount::class);
    }

    /**
     * Whether the zone's DNS is at Cloudflare: its records are written
     * there instead of to this server's PowerDNS.
     */
    public function atCloudflare(): bool
    {
        return $this->cloudflare_account_id !== null && $this->cloudflare_zone_id !== null;
    }

    /**
     * Cloudflare's state of the zone: "active", or "pending" while the
     * registrar still lists other name servers.
     */
    public function cloudflareStatus(): ?string
    {
        return $this->atCloudflare() ? ($this->cloudflare_info['status'] ?? null) : null;
    }

    /**
     * The name servers visitors' resolvers are sent to: Cloudflare's for a
     * zone there, the NS records otherwise.
     *
     * @return list<string> without the trailing dot
     */
    public function publicNameservers(): array
    {
        if ($this->atCloudflare()) {
            return array_values($this->cloudflare_info['name_servers'] ?? []);
        }

        return array_map(fn ($ns) => rtrim($ns, '.'), $this->nameservers());
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<DnsRecord, $this>
     */
    public function records(): HasMany
    {
        return $this->hasMany(DnsRecord::class)->orderBy('id');
    }

    /**
     * Name servers listed at the zone apex.
     *
     * @return list<string>
     */
    public function nameservers(): array
    {
        return $this->records->where('name', '@')->where('type', 'NS')->pluck('content')->values()->all();
    }

    /**
     * The SOA mailbox, defaulting to hostmaster@<zone>.
     */
    public function hostmasterEmail(): string
    {
        return $this->hostmaster ?: 'hostmaster@'.$this->name;
    }

    /**
     * Parameters for the agent's dns.zone.apply method.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        $this->loadMissing('records');

        return [
            'zone' => $this->name,
            'soa' => [
                'primary' => $this->nameservers()[0] ?? 'ns1.'.$this->name.'.',
                'hostmaster' => $this->hostmasterEmail(),
                'refresh' => $this->refresh,
                'retry' => $this->retry,
                'expire' => $this->expire,
                'minimum' => $this->minimum,
                'ttl' => $this->default_ttl,
            ],
            'records' => $this->records->map(fn (DnsRecord $r) => [
                'name' => $r->name,
                'type' => $r->type,
                'ttl' => $r->ttl,
                'content' => $r->agentContent(),
            ])->values()->all(),
            'dnssec' => array_filter(['enabled' => (bool) $this->dnssec, 'key' => $this->dnssec_key], fn ($v) => $v !== null),
        ];
    }

    /**
     * The DS record to give the registrar (SHA-256), once PowerDNS has
     * signed the zone.
     *
     * @return array{key_tag: int, algorithm: int, digest_type: int, digest: string, record: string}|null
     */
    public function dsRecord(): ?array
    {
        if (! $this->dnssec) {
            return null;
        }
        foreach ($this->dnssec_info['ds'] ?? [] as $ds) {
            $f = preg_split('/\s+/', trim($ds));
            if (count($f) === 4 && $f[2] === '2') {
                return ['key_tag' => (int) $f[0], 'algorithm' => (int) $f[1], 'digest_type' => 2, 'digest' => strtoupper($f[3]), 'record' => $this->name.'. IN DS '.$ds];
            }
        }

        return null;
    }

    /**
     * The zone in BIND zone file format, for export.
     */
    public function zoneFile(): string
    {
        $this->loadMissing('records');
        $origin = $this->name.'.';
        [$local, $domain] = explode('@', $this->hostmasterEmail(), 2) + [1 => $this->name];
        $mbox = str_replace('.', '\\.', $local).'.'.$domain.'.';
        $lines = [
            '; '.$this->name.' exported from Serfex on '.now()->toIso8601String(),
            '$ORIGIN '.$origin,
            '$TTL '.$this->default_ttl,
            sprintf('@ %d IN SOA %s %s %d %d %d %d %d', $this->default_ttl, $this->nameservers()[0] ?? 'ns1.'.$origin, $mbox,
                $this->serial ?? 0, $this->refresh, $this->retry, $this->expire, $this->minimum),
        ];
        foreach ($this->records as $r) {
            $content = $r->type === 'TXT'
                ? implode(' ', array_map(fn ($c) => '"'.addcslashes($c, '"\\').'"', str_split($r->content, 255)))
                : $r->agentContent();
            $lines[] = sprintf('%s %d IN %s %s', $r->name, $r->ttl, $r->type, $content);
        }

        return implode("\n", $lines)."\n";
    }
}
