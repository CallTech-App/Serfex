<?php

namespace App\Models;

use App\Enums\ZoneStatus;
use App\Models\Concerns\OwnedThroughSite;
use Database\Factories\DnsZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['site_id', 'name', 'status', 'hostmaster', 'default_ttl', 'refresh', 'retry', 'expire', 'minimum', 'serial', 'last_error', 'synced_at'])]
class DnsZone extends Model
{
    /** @use HasFactory<DnsZoneFactory> */
    use HasFactory;

    use OwnedThroughSite;

    protected $attributes = [
        'status' => 'syncing',
    ];

    protected function casts(): array
    {
        return [
            'status' => ZoneStatus::class,
            'synced_at' => 'datetime',
            'serial' => 'integer',
        ];
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
        ];
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
