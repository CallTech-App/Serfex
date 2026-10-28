<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A hosting customer. They own Linux accounts and, through them, sites
 * with their mail, DNS, certificates and Laravel applications, within
 * their package. They sign in with their own panel login.
 */
#[Fillable(['package_id', 'disk_override', 'disk_override_mb', 'company', 'name', 'email', 'phone', 'notes', 'username', 'suspended_at', 'suspend_reason', 'disk_full_at', 'delete_sites'])]
class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'disk_full_at' => 'datetime',
            'delete_sites' => 'boolean',
            'disk_override' => 'boolean',
            'disk_override_mb' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * @return HasMany<SystemUser, $this>
     */
    public function systemUsers(): HasMany
    {
        return $this->hasMany(SystemUser::class);
    }

    /**
     * @return HasManyThrough<Site, SystemUser, $this>
     */
    public function sites(): HasManyThrough
    {
        return $this->hasManyThrough(Site::class, SystemUser::class);
    }

    /**
     * The customer's panel login.
     *
     * @return HasOne<User, $this>
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * The customer's disk limit in bytes: their own when an administrator
     * set one, else the package's. Null is unlimited.
     */
    public function diskLimit(): ?int
    {
        if ($this->disk_override) {
            return $this->disk_override_mb === null ? null : $this->disk_override_mb * 1024 * 1024;
        }

        return $this->package->limit('disk');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null || $this->trashed();
    }

    /**
     * The name lists show: the company, or the person when there is none.
     */
    public function displayName(): string
    {
        return $this->company ?: $this->name;
    }

    /**
     * True when $site belongs to one of the customer's accounts.
     */
    public function owns(Site $site): bool
    {
        return $site->systemUser?->customer_id === $this->id;
    }

    /**
     * @param  Builder<Customer>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';
        $query->where(fn ($q) => $q->whereRaw('lower(company) like ?', [$like])
            ->orWhereRaw('lower(name) like ?', [$like])
            ->orWhereRaw('lower(email) like ?', [$like])
            ->orWhereHas('systemUsers', fn ($u) => $u->where('name', 'like', $like)));
    }
}
