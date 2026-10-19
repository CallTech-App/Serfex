<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A preview environment of a Laravel application: a branch or pull request
 * deployed on its own subdomain, with its own site, .env and database.
 */
#[Fillable(['laravel_app_id', 'site_id', 'preview_app_id', 'name', 'branch', 'pr_number', 'pr_title', 'pr_url', 'source', 'status', 'error', 'created_by'])]
class LaravelPreview extends Model
{
    protected $attributes = [
        'source' => 'manual',
        'status' => 'creating',
    ];

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function previewApp(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'preview_app_id');
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * The preview's host name: <name>.<application's domain>.
     */
    public function domain(): string
    {
        return $this->name.'.'.$this->app->site->domain;
    }

    public function isBusy(): bool
    {
        return in_array($this->status, ['creating', 'deleting'], true);
    }
}
