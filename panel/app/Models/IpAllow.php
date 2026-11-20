<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An address or prefix that is never blocked: not by hand, not by
 * Fail2ban, not by Serfex AI. The agent keeps the list in the firewall, in
 * Nginx and in Fail2ban's jails.
 */
#[Fillable(['address', 'country', 'note', 'user_id'])]
class IpAllow extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
