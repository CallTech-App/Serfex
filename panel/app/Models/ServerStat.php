<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One hour of the whole server: CPU, memory, disk, network and load (kept 400 days).
 */
#[Fillable(['hour', 'cpu', 'cpu_max', 'memory', 'memory_max', 'memory_total', 'disk_used', 'disk_total', 'net_rx', 'net_tx', 'load'])]
class ServerStat extends Model
{
    protected $table = 'server_stats';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['hour' => 'datetime'];
    }
}
