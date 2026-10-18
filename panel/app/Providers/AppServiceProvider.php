<?php

namespace App\Providers;

use App\Services\Agent\AgentClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AgentClient::class, fn ($app) => new AgentClient(
            socket: $app['config']->get('servik.agent.socket'),
            timeout: $app['config']->get('servik.agent.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
