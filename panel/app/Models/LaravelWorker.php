<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A group of identical queue:work processes of a Laravel application.
 */
#[Fillable(['laravel_app_id', 'name', 'connection', 'queue', 'processes', 'timeout', 'tries', 'backoff', 'sleep', 'max_jobs', 'max_time', 'memory'])]
class LaravelWorker extends Model
{
    protected $attributes = [
        'queue' => 'default',
        'processes' => 1,
        'timeout' => 60,
        'tries' => 1,
        'backoff' => 0,
        'sleep' => 3,
        'max_jobs' => 1000,
        'max_time' => 3600,
        'memory' => 128,
    ];

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    /**
     * The worker as the agent's laravel.queue.apply takes it.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        return [
            'name' => $this->name,
            'connection' => (string) $this->connection,
            'queue' => $this->queue,
            'processes' => $this->processes,
            'timeout' => $this->timeout,
            'tries' => $this->tries,
            'backoff' => $this->backoff,
            'sleep' => $this->sleep,
            'max_jobs' => $this->max_jobs,
            'max_time' => $this->max_time,
            'memory' => $this->memory,
        ];
    }

    /**
     * The queues to work, first to last.
     *
     * @return list<string>
     */
    public function queues(): array
    {
        return array_values(array_filter(explode(',', $this->queue)));
    }
}
