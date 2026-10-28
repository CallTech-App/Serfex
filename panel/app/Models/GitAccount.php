<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A GitHub, GitLab or Bitbucket account a panel user connected with
 * OAuth. Its token lists the user's repositories and adds Serfex's
 * deploy key and webhook to the one they pick; code is never fetched
 * with it. Each user connects their own accounts.
 */
#[Fillable(['user_id', 'provider', 'remote_id', 'username', 'access_token', 'refresh_token', 'expires_at'])]
class GitAccount extends Model
{
    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<GitRepository, $this>
     */
    public function gitRepositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }

    /**
     * @return HasMany<LaravelApp, $this>
     */
    public function laravelApps(): HasMany
    {
        return $this->hasMany(LaravelApp::class);
    }

    /**
     * True when the token is about to run out and has to be refreshed
     * first (GitLab and Bitbucket tokens last two hours).
     */
    public function expiresSoon(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->addMinute());
    }
}
