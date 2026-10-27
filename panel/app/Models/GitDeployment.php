<?php

namespace App\Models;

use App\Enums\GitDeploymentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Git deploy of a site's folder: a deploy of the branch, a return to
 * an earlier commit (rollback) or the undo of the first deploy. Its
 * output is the agent's run log named run.
 */
#[Fillable(['git_repository_id', 'number', 'kind', 'status', 'branch', 'commit', 'previous_commit', 'message', 'author',
    'changed_files', 'saved_files', 'triggered_by', 'run', 'error', 'started_at', 'finished_at'])]
class GitDeployment extends Model
{
    protected $attributes = [
        'status' => 'queued',
        'kind' => 'deploy',
    ];

    protected function casts(): array
    {
        return [
            'status' => GitDeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GitRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(GitRepository::class, 'git_repository_id');
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

        return $seconds < 60 ? __(':seconds sn', ['seconds' => $seconds]) : __(':minutes dk :seconds sn', ['minutes' => intdiv($seconds, 60), 'seconds' => str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT)]);
    }
}
