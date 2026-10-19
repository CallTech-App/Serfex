<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureHostingAccess;
use App\Http\Middleware\EnsureTwoFactor;
use App\Livewire\Access;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Databases\DatabaseServers;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Remembers the agent's database status for one request or job.
        $this->app->scoped(DatabaseServers::class);
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

        // GET /up answers 200 only when the panel reaches its database: an
        // update returns to the previous release when it does not.
        Event::listen(DiagnosingHealth::class, fn () => DB::connection()->getPdo()->query('select 1'));

        // Every Livewire mount and call is authorized here, before the
        // component runs (see Access).
        on('call', fn (Component $component, string $method) => Access::authorize($component, $method));
        on('mount', fn (Component $component) => Access::authorize($component, null));
        // Updates of an open page pass the same checks as loading it: a
        // suspended customer's open tab stops working too.
        Livewire::addPersistentMiddleware([EnsureActiveAccount::class, EnsureTwoFactor::class, EnsureHostingAccess::class, EnsureAdmin::class]);
    }
}
