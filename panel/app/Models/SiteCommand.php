<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Composer or Node.js command run in a folder of a site (Site →
 * Composer ve Node.js). Its output is the agent's run log named run.
 */
#[Fillable(['site_id', 'user_id', 'tool', 'path', 'command', 'run', 'status', 'exit_code', 'error', 'finished_at'])]
class SiteCommand extends Model
{
    protected $attributes = [
        'path' => '',
        'status' => 'queued',
    ];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
