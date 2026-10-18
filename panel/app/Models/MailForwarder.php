<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['mail_domain_id', 'name', 'destinations'])]
class MailForwarder extends Model
{
    /** The name of the forwarder that takes mail for unknown addresses. */
    public const CATCH_ALL = '*';

    protected function casts(): array
    {
        return ['destinations' => 'array'];
    }

    /**
     * @return BelongsTo<MailDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(MailDomain::class, 'mail_domain_id');
    }

    public function isCatchAll(): bool
    {
        return $this->name === self::CATCH_ALL;
    }
}
