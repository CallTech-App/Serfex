<?php

namespace App\Models;

use App\Enums\GitDeploymentStatus;
use App\Models\Concerns\HasGitRepository;
use App\Models\Concerns\OwnedThroughSite;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The Git repository a site's folder (usually public_html) is deployed
 * from (Git dağıtımı). Laravel applications deploy through LaravelApp.
 */
#[Fillable(['site_id', 'repository', 'branch', 'path', 'token', 'commands', 'auto_deploy', 'deploy_key', 'live_commit'])]
class GitRepository extends Model
{
    use HasGitRepository;
    use OwnedThroughSite;

    protected $hidden = ['token', 'deploy_key', 'webhook_token'];

    protected $attributes = [
        'branch' => 'main',
        'path' => 'public_html',
        'auto_deploy' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (GitRepository $repo) {
            $repo->webhook_token ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'auto_deploy' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<GitDeployment, $this>
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(GitDeployment::class)->orderByDesc('number');
    }

    public function hasRunningDeployment(): bool
    {
        return $this->deployments()->whereIn('status', [GitDeploymentStatus::Queued, GitDeploymentStatus::Running])->exists();
    }

    public function nextNumber(): int
    {
        return (int) $this->deployments()->max('number') + 1;
    }

    /**
     * True for an SSH address, which the site's deploy key opens.
     */
    public function usesKey(): bool
    {
        return ! str_starts_with($this->repository, 'https://');
    }

    /**
     * The same address as Laravel applications' deploy hooks; the token
     * tells them apart.
     */
    public function webhookUrl(): string
    {
        return route('laravel.webhook', $this->webhook_token);
    }

    /**
     * The site, as the agent's site.git methods name it.
     *
     * @return array{domain: string, user: string, php_version: string}
     */
    public function target(): array
    {
        return [
            'domain' => $this->site->domain,
            'user' => $this->site->systemUser->name,
            'php_version' => $this->site->php_version,
        ];
    }
}
