<?php

namespace App\Providers;

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFullAdmin;
use App\Http\Middleware\EnsureHostingAccess;
use App\Http\Middleware\EnsureTwoFactor;
use App\Livewire\Access;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Audit\AuditLog;
use App\Services\Databases\DatabaseServers;
use App\Services\Settings\PanelSettings;
use App\Support\ReadOnlyGuard;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Blade;
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

        // The panel's time zone and session length (Araçlar ve Ayarlar).
        PanelSettings::apply();
        // Fixed labels echoed in views are shown in the reader's language
        // (see Locales::label).
        Blade::setEchoFormat('e(\\App\\Support\\Locales::label(%s))');

        // Every Livewire mount and call is authorized here, before the
        // component runs (see Access).
        on('call', fn (Component $component, string $method) => Access::authorize($component, $method));
        on('mount', fn (Component $component) => Access::authorize($component, null));
        // Read-only administrators look but do not change (ReadOnlyGuard).
        on('call', fn (Component $component, string $method, $params, $context, $returnEarly) => ReadOnlyGuard::gate($component, $method, $returnEarly));
        DB::beforeExecuting(fn (string $query) => ReadOnlyGuard::query($query));
        // İşlem günlüğü: what each call told the person it did, and Undo
        // links opened from the log.
        on('call', fn (Component $component, string $method, $params) => AuditLog::watch($component, $method, (array) $params));
        on('mount', fn (Component $component) => AuditLog::undoOnMount($component));
        Event::listen(Login::class, function (Login $event) {
            if (request()->is('login', 'login/dogrulama', 'davet/*')) {
                $event->user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
                AuditLog::record(__('Panele giriş yaptı.'), $event->user);
            }
        });
        // Updates of an open page pass the same checks as loading it: a
        // suspended customer's open tab stops working too.
        Livewire::addPersistentMiddleware([EnsureActiveAccount::class, EnsureTwoFactor::class, EnsureHostingAccess::class, EnsureAdmin::class, EnsureFullAdmin::class]);
    }
}
