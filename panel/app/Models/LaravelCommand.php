<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Artisan, Composer or Node.js command run from the panel. Its output is
 * the agent's run log named run.
 */
#[Fillable(['laravel_app_id', 'user_id', 'tool', 'command', 'run', 'status', 'exit_code', 'error', 'finished_at'])]
class LaravelCommand extends Model
{
    protected function casts(): array
    {
        return [
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

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }
}
