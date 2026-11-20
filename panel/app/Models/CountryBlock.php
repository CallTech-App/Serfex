<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A country whose visitors the server refuses (Güvenlik > IP engelleri >
 * Ülkeler). The agent keeps the list in Nginx and, when the whole server
 * is closed to them, in the firewall.
 */
#[Fillable(['code', 'user_id'])]
class CountryBlock extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
