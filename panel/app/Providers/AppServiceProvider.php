<?php

namespace App\Providers;

use App\Livewire\Laravel\GuardsAppCalls;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;

use function Livewire\on;

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
        foreach (['view', 'operate', 'manage'] as $ability) {
            Gate::define('laravel.'.$ability, fn ($user, LaravelApp $app) => $user->canOnApp($app, $ability));
        }

        // Every Livewire call is authorized here, before the method runs:
        // accounts that are not administrators may use only the Laravel
        // pages, and there only what their role on the application allows.
        on('call', function (Component $component, string $method) {
            $user = auth()->user();
            if ($user === null) {
                abort(403);
            }
            if (in_array(GuardsAppCalls::class, class_uses_recursive($component), true)) {
                $app = LaravelApp::find($component->appId);
                if ($app !== null && ! $user->canOnApp($app, $component->ability($method))) {
                    abort(403);
                }

                return;
            }
            if (! $user->is_admin && (! str_starts_with($component::class, 'App\\Livewire\\Laravel\\')
                || (defined($component::class.'::ADMIN_METHODS') && in_array($method, $component::ADMIN_METHODS, true)))) {
                abort(403);
            }
        });

        on('mount', function (Component $component) {
            $user = auth()->user();
            if ($user !== null && ! $user->is_admin && ! str_starts_with($component::class, 'App\\Livewire\\Laravel\\')) {
                abort(403);
            }
        });
    }
}
