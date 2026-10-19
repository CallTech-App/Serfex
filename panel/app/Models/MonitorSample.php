<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a site (or, with no site, the whole server) used during one minute.
 */
#[Fillable(['recorded_at', 'site_id', 'cpu', 'memory', 'io', 'requests', 'server_errors', 'php_busy', 'php_max', 'db_active', 'mail_per_hour'])]
class MonitorSample extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
