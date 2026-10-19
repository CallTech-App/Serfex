<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Linux account on the server that owns sites, and the customer it
 * belongs to, if any.
 */
#[Fillable(['customer_id', 'name', 'uid', 'home', 'disk_bytes', 'disk_measured_at'])]
class SystemUser extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'disk_measured_at' => 'datetime',
        ];
    }

    /**
     * The customer the account belongs to; a deleted customer stays
     * visible until their undo window has passed.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<Site, $this>
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
