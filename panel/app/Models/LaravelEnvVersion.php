<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A .env as it was before a save. The content is encrypted with the
 * panel's key, so database dumps do not carry the application's secrets.
 */
#[Fillable(['laravel_app_id', 'content', 'summary', 'user_name'])]
class LaravelEnvVersion extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }
}
