<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One hour of what a site served (UTC hours, kept a month).
 */
#[Fillable(['site_id', 'hour', 'requests', 'bytes', 'visitors', 'pages', 'bot_requests', 'bot_bytes', 's2xx', 's3xx', 's4xx', 's5xx'])]
class SiteStatHour extends Model
{
    protected $table = 'site_stats_hourly';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['hour' => 'datetime'];
    }
}
