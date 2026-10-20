<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of a site's top pages, referrers, countries or bots on one day; an empty value adds up the rest.
 */
#[Fillable(['site_id', 'day', 'kind', 'value', 'requests', 'bytes', 'visitors'])]
class SiteStatItem extends Model
{
    protected $table = 'site_stats_items';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
