<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Cloudflare API token the administrator connected. Zones whose DNS is
 * at Cloudflare are written there with it; the token is stored encrypted
 * and never shown again.
 */
#[Hidden(['token'])]
#[Fillable(['name', 'token', 'account_id', 'account_name', 'zones_count', 'checks', 'status', 'last_error', 'checked_at'])]
class CloudflareAccount extends Model
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    /** What a check looks at, with its name. */
    public const CHECKS = [
        'dns' => 'DNS',
        'ssl' => 'SSL modu',
        'create' => 'Alan adı ekleme',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'zones_count' => 'integer',
            'checks' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DnsZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(DnsZone::class);
    }

    /**
     * The name given here, with Cloudflare's account name when it differs.
     */
    public function label(): string
    {
        return $this->account_name !== null && $this->account_name !== $this->name ? $this->name.' ('.$this->account_name.')' : $this->name;
    }

    /**
     * The names of what the token was found to be able to do.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return array_values(array_map(fn ($k) => __(self::CHECKS[$k]), array_keys(array_filter($this->checks ?? []))));
    }
}
