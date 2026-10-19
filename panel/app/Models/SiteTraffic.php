<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Bytes a site sent to its visitors in one month.
 */
#[Fillable(['site_id', 'month', 'bytes'])]
class SiteTraffic extends Model
{
    protected $table = 'site_traffic';

    protected function casts(): array
    {
        return [
            'month' => 'date',
        ];
    }
}
