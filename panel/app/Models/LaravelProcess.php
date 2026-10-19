<?php

namespace App\Models;

use App\Services\Laravel\CommandLine;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A long-running process of a Laravel application, run by systemd:
 * Horizon, Octane, Reverb or a command of the customer's own.
 */
#[Fillable(['laravel_app_id', 'name', 'kind', 'enabled', 'port', 'server', 'workers', 'max_requests', 'command'])]
class LaravelProcess extends Model
{
    public const KINDS = ['horizon' => 'Horizon', 'octane' => 'Octane', 'reverb' => 'Reverb', 'custom' => 'Özel süreç'];

    public const OCTANE_SERVERS = ['frankenphp' => 'FrankenPHP', 'swoole' => 'Swoole', 'roadrunner' => 'RoadRunner'];

    /** Ports Octane and Reverb listen on, on 127.0.0.1. */
    public const PORT_RANGE = [20000, 29999];

    protected $attributes = [
        'enabled' => true,
        'workers' => 0,
        'max_requests' => 500,
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<LaravelApp, $this>
     */
    public function app(): BelongsTo
    {
        return $this->belongsTo(LaravelApp::class, 'laravel_app_id');
    }

    public function label(): string
    {
        return $this->kind === 'custom' ? $this->name : self::KINDS[$this->kind];
    }

    /**
     * The process as the agent's laravel.process.apply takes it.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'enabled' => $this->enabled,
            'port' => (int) $this->port,
            'server' => (string) $this->server,
            'workers' => $this->workers,
            'max_requests' => $this->max_requests,
            'command' => $this->kind === 'custom' ? CommandLine::split((string) $this->command) : [],
        ];
    }

    /**
     * A free port for a new Octane or Reverb process or Redis instance.
     */
    public static function freePort(): int
    {
        // Applications' Redis instances take ports from the same range.
        $used = [...static::query()->whereNotNull('port')->pluck('port')->all(), ...LaravelApp::whereNotNull('redis_port')->pluck('redis_port')->all()];
        for ($p = self::PORT_RANGE[0]; $p <= self::PORT_RANGE[1]; $p++) {
            if (! in_array($p, $used, true)) {
                return $p;
            }
        }
        throw new \RuntimeException('No free port left for Laravel processes.');
    }
}
