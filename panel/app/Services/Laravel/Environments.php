<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Models\LaravelEnvVersion;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * An application's .env: read and written through the agent as the
 * site's account. Every save keeps the file as it was in the history
 * (the last HISTORY versions) and rebuilds the config cache.
 */
class Environments
{
    /** How many earlier versions are kept. */
    public const HISTORY = 30;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * The .env (null when there is none) and .env.example.
     *
     * @return array{env: string|null, example: string|null}
     *
     * @throws AgentException
     */
    public function read(LaravelApp $app): array
    {
        $files = $this->agent->call('laravel.env.get', $app->agentApp(), config('servik.agent.job_timeout'));

        return ['env' => $files['env'] ?? null, 'example' => $files['example'] ?? null];
    }

    /**
     * Writes $content over the .env whose text was $before. Returns the
     * history entry of $before and whether the config cache was rebuilt.
     *
     * @return array{version: LaravelEnvVersion, cached: bool}
     *
     * @throws AgentException
     */
    public function save(LaravelApp $app, string $before, string $content, ?string $by): array
    {
        $result = $this->agent->call('laravel.env.set', $app->agentApp() + ['content' => $content], config('servik.agent.job_timeout'));
        $version = $app->envVersions()->create([
            'content' => $before,
            'summary' => EnvFile::summary($before, $content),
            'user_name' => $by,
        ]);
        $app->envVersions()->whereNotIn('id', $app->envVersions()->limit(self::HISTORY)->pluck('id'))->delete();

        return ['version' => $version, 'cached' => (bool) ($result['cached'] ?? false)];
    }
}
