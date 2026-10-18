<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One deploy (or the install, or a return to an earlier release) of a
 * Laravel application. Its output is the agent's run log named run.
 */
#[Fillable(['laravel_app_id', 'number', 'kind', 'status', 'ref', 'commit', 'message', 'triggered_by', 'release', 'run', 'migrate', 'frontend',
    'health_status', 'health_ms', 'error', 'started_at', 'finished_at'])]
class LaravelDeployment extends Model
{
    protected $attributes = [
        'status' => 'queued',
        'kind' => 'deploy',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'migrate' => 'boolean',
            'frontend' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    public function shortCommit(): ?string
    {
        return $this->commit ? substr($this->commit, 0, 7) : null;
    }

    /**
     * "1 dk 09 sn" or "58 sn"; null while it has not finished.
     */
    public function durationLabel(): ?string
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }
        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at);

        return $seconds < 60 ? $seconds.' sn' : intdiv($seconds, 60).' dk '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).' sn';
    }
}
