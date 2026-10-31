<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One measurement of a database server (agent service.measure): the
 * machine, settings, counters since the server started and its most
 * expensive statements.
 */
#[Fillable(['unit', 'data', 'created_at'])]
class TuningSample extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
