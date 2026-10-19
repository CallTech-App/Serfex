<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A deploy token of one Laravel application, for CI and the command line.
 * Only its SHA-256 is stored; the token is shown once.
 */
#[Fillable(['laravel_app_id', 'name', 'token_hash', 'last_four', 'created_by', 'last_used_at', 'last_used_ip', 'last_used_agent', 'revoked_at'])]
class LaravelApiToken extends Model
{
    public const PREFIX = 'svk_';

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    /**
     * Creates a token; returns the model and the plain token.
     *
     * @return array{0: LaravelApiToken, 1: string}
     */
    public static function issue(LaravelApp $app, string $name, ?User $by = null): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = $app->apiTokens()->create([
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'last_four' => substr($plain, -4),
            'created_by' => $by?->name,
        ]);

        return [$token, $plain];
    }

    /**
     * The live token matching a plain token, if any.
     */
    public static function findPlain(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 100) {
            return null;
        }

        return static::with('app.site')->where('token_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }
}
