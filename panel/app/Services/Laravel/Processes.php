<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The queue workers and scheduler of an application, as systemd units the
 * agent writes.
 */
class Processes
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Make the server run exactly the application's workers and, when it is
     * on, its scheduler.
     *
     * @throws AgentException
     */
    public function apply(LaravelApp $app): void
    {
        $app->loadMissing('site.systemUser', 'workers');
        $this->agent->call('laravel.queue.apply', $app->agentApp() + [
            'workers' => $app->workers->map->agentSpec()->values()->all(),
        ], config('servik.agent.job_timeout'));
        $this->agent->call('laravel.schedule.apply', $app->agentApp() + ['enabled' => $app->scheduler], config('servik.agent.job_timeout'));
    }
}
