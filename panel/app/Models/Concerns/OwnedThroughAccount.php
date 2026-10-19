<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * For models that belong to a Linux account (system_user_id): a customer
 * sees the ones of their own accounts, an administrator sees all of them.
 */
trait OwnedThroughAccount
{
    /**
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_admin) {
            return;
        }
        if ($user->customer_id === null) {
            $query->whereRaw('false');

            return;
        }
        $query->whereHas('systemUser', fn ($u) => $u->where('customer_id', $user->customer_id));
    }
}
