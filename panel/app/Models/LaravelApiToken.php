<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A deploy token of one Laravel application, for CI and the command line.
 * Only its SHA-256 is stored; the token is shown once.
 *
 * What it may do (ability): deploy, follow deploys and open previews; or
 * operate, which adds .env, commands, queues, maintenance mode and logs.
 */
#[Fillable(['laravel_app_id', 'name', 'ability', 'token_hash', 'last_four', 'created_by', 'last_used_at', 'last_used_ip', 'last_used_agent', 'revoked_at'])]
class LaravelApiToken extends Model
{
    public const PREFIX = 'sfx_';

    /** Abilities: key => [name, what the token may do]. */
    public const ABILITIES = [
        'deploy' => ['Dağıtım', 'Dağıtır, dağıtımları izler, önizleme açar ve kaldırır.'],
        'operate' => ['Geliştirici', 'Dağıtımın yanı sıra .env, komutlar, kuyruklar, bakım modu ve günlükler.'],
    ];

    protected $attributes = [
        'ability' => 'deploy',
    ];

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
    public static function issue(LaravelApp $app, string $name, ?User $by = null, string $ability = 'deploy'): array
    {
        $plain = self::PREFIX.Str::random(40);
        $token = $app->apiTokens()->create([
            'name' => $name,
            'ability' => $ability,
            'token_hash' => hash('sha256', $plain),
            'last_four' => substr($plain, -4),
            'created_by' => $by?->name,
        ]);

        return [$token, $plain];
    }

    /**
     * Whether the token may do $ability (view, deploy or operate) on its
     * application. Nothing a token does reaches manage.
     */
    public function can(string $ability): bool
    {
        return match ($ability) {
            'view', 'deploy' => true,
            'operate' => $this->ability === 'operate',
            default => false,
        };
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
