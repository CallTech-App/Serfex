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
#[Fillable(['name', 'token', 'account_id', 'account_name', 'zones_count', 'status', 'last_error', 'checked_at'])]
class CloudflareAccount extends Model
{
    public const OK = 'ok';

    public const INVALID = 'invalid';

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'zones_count' => 'integer',
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
     * Cloudflare's own account name, or the name given here.
     */
    public function label(): string
    {
        return $this->name;
    }
}
