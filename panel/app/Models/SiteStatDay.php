<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One day of what a site served (days in the panel's time zone, kept two years).
 */
#[Fillable(['site_id', 'day', 'requests', 'bytes', 'visitors', 'pages', 'bot_requests', 'bot_bytes', 's2xx', 's3xx', 's4xx', 's5xx'])]
class SiteStatDay extends Model
{
    protected $table = 'site_stats_daily';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }
}
