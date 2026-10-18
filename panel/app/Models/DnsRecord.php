<?php

namespace App\Models;

use App\Support\DnsRecords;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dns_zone_id', 'name', 'type', 'content', 'priority', 'ttl'])]
class DnsRecord extends Model
{
    protected function casts(): array
    {
        return ['priority' => 'integer', 'ttl' => 'integer'];
    }

    /**
     * @return BelongsTo<DnsZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    /**
     * Content as the agent expects it: MX and SRV carry their priority.
     */
    public function agentContent(): string
    {
        return in_array($this->type, DnsRecords::WITH_PRIORITY, true)
            ? ($this->priority ?? 0).' '.$this->content
            : $this->content;
    }

    /**
     * The fields that make up the record, for snapshots and comparisons.
     *
     * @return array{name: string, type: string, content: string, priority: ?int, ttl: int}
     */
    public function toRecord(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'content' => $this->content,
            'priority' => $this->priority,
            'ttl' => $this->ttl,
        ];
    }
}
