<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Models\LaravelProcess;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Str;

/**
 * The Redis instance of a Laravel application: its own redis-server,
 * running as the site's user on a local port with a password (Redis is
 * installed from Yazılım Yönetimi). Previews use their parent's instance
 * with a key prefix of their own.
 */
class Redis
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Starts the application's instance and returns the .env lines for
     * it: cache, sessions and queues on Redis.
     *
     * @return array<string, string>
     *
     * @throws AgentException
     */
    public function connect(LaravelApp $app): array
    {
        $app->loadMissing('site.systemUser');
        $port = $app->redis_port ?? LaravelProcess::freePort();
        $password = $app->redis_password ?? Str::random(40);
        $this->agent->call('redis.apply', [
            'domain' => $app->site->domain,
            'user' => $app->site->systemUser->name,
            'port' => $port,
            'password' => $password,
        ], config('serfex.agent.job_timeout'));
        $app->update(['redis_port' => $port, 'redis_password' => $password]);

        return self::env($port, $password);
    }

    /**
     * Stops and deletes the application's instance with its data.
     *
     * @throws AgentException
     */
    public function disconnect(LaravelApp $app): void
    {
        $app->loadMissing('site');
        $this->agent->call('redis.remove', ['domain' => $app->site->domain], config('serfex.agent.job_timeout'));
        $app->update(['redis_port' => null, 'redis_password' => null]);
    }

    /**
     * @return array<string, string>
     */
    public static function env(int $port, string $password): array
    {
        return [
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => (string) $port,
            'REDIS_PASSWORD' => $password,
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
        ];
    }
}
