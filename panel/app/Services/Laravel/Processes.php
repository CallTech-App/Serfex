<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/**
 * The queue workers, scheduler and long-running processes (Horizon,
 * Octane, Reverb, custom) of an application, as systemd units the agent
 * writes.
 */
class Processes
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Make the server run exactly the application's workers, processes and,
     * when it is on, its scheduler. While Horizon runs, it works the queues
     * and the queue:work workers stay off.
     *
     * @throws AgentException
     */
    public function apply(LaravelApp $app): void
    {
        $app->loadMissing('site.systemUser', 'workers', 'processes');
        $timeout = config('servik.agent.job_timeout');
        // A suspended customer's application runs nothing; its settings
        // stay and come back when the suspension is lifted.
        $off = $app->site->isSuspended();
        $this->agent->call('laravel.queue.apply', $app->agentApp() + [
            'workers' => $off || $this->horizon($app) ? [] : $app->workers->map->agentSpec()->values()->all(),
        ], $timeout);
        $this->agent->call('laravel.schedule.apply', $app->agentApp() + ['enabled' => ! $off && $app->scheduler], $timeout);
        $this->agent->call('laravel.process.apply', $app->agentApp() + [
            'processes' => $off ? [] : $app->processes->map->agentSpec()->values()->all(),
        ], $timeout);
    }

    /**
     * Rewrites the site's Nginx configuration for the application's proxy,
     * WebSockets and maintenance page.
     *
     * @throws AgentException
     */
    public function applyWeb(LaravelApp $app): void
    {
        $app->loadMissing('site.systemUser', 'site.certificate');
        $site = $app->site;
        $site->setRelation('laravelApp', $app->load('processes'));
        $this->agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
    }

    /**
     * True when Horizon works the application's queues.
     */
    public function horizon(LaravelApp $app): bool
    {
        return $app->processes->contains(fn ($p) => $p->kind === 'horizon' && $p->enabled);
    }
}
