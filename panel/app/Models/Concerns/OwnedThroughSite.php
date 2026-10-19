<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * For models that belong to a site: a customer sees the ones on their own
 * sites, an administrator sees all of them.
 */
trait OwnedThroughSite
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->whereHas('site', fn ($s) => $s->visibleTo($user));
        }
    }
}
