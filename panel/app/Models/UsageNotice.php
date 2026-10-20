<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A disk or traffic warning a customer was mailed (level warn or crit).
 */
#[Fillable(['customer_id', 'kind', 'level', 'sent_at'])]
class UsageNotice extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
