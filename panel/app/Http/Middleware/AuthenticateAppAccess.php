<?php

namespace App\Http\Middleware;

use App\Models\LaravelApiToken;
use App\Models\LaravelApp;
use App\Services\Settings\ApiKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Laravel application API (/api/v1/apps/{domain}) takes either the
 * application's own token (Erişim ve API), which works only for that
 * application and within its ability, or a person's API key, which works
 * with the person's role on the application.
 *
 * $ability is what the route needs: view, deploy, operate or manage
 * (deploy is operate for people). $feature, when given, is the package
 * feature a customer's application needs (shell for commands).
 */
class AuthenticateAppAccess
{
    public function __construct(private readonly ApiKeys $keys) {}

    public function handle(Request $request, Closure $next, string $ability = 'view', ?string $feature = null): Response
    {
        $plain = (string) $request->bearerToken();
        $domain = mb_strtolower((string) $request->route('domain'));

        if (str_starts_with($plain, ApiKeys::PREFIX)) {
            $key = $this->keys->find($plain);
            if ($key === null || ! $key->isUsable()) {
                return AuthenticateApiKey::refuse(401, $key?->revoked_at ? __('Bu API anahtarı iptal edildi.') : ($key ? __('Bu API anahtarının süresi doldu.') : __('API anahtarı yok ya da geçersiz.')));
            }
            if (! $key->allowsIp($request->ip())) {
                return AuthenticateApiKey::refuse(403, __('Bu API anahtarı :ip adresinden kullanılamaz.', ['ip' => $request->ip()]));
            }
            $user = $key->user;
            if (($refusal = AuthenticateApiKey::userRefusal($key, $user)) !== null) {
                return AuthenticateApiKey::refuse(403, $refusal);
            }
            if (! $request->isMethodSafe() && ($key->scope !== 'write' || $user->isReadOnly())) {
                return AuthenticateApiKey::refuse(403, $user->isReadOnly() ? __('Salt okunur yöneticiler değişiklik yapamaz.') : __('Bu API anahtarı salt okunur.'));
            }
            $app = $this->find($domain);
            if ($app === null || $user->laravelRole($app) === null) {
                return AuthenticateApiKey::refuse(404, __(':domain adında bir Laravel uygulaması yok.', ['domain' => $domain]));
            }
            if (! $user->canOnApp($app, $ability === 'deploy' ? 'operate' : $ability)) {
                return AuthenticateApiKey::refuse(403, __('Bu uygulamadaki rolünüz bunu yapmaya yetmiyor.'));
            }
            if ($feature !== null && ! $user->mayUseOnApp($app, $feature)) {
                return AuthenticateApiKey::refuse(403, __('Paket bu özelliği içermiyor.'));
            }
            if (($limited = AuthenticateApiKey::limit('serfex-api:'.$key->id)) !== null) {
                return $limited;
            }
            if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute()) || $key->last_used_ip !== $request->ip()) {
                $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
            }
            Auth::setUser($user);
            $request->attributes->set('api_key', $key);
            $request->attributes->set('actor', $user->name);
        } else {
            $token = LaravelApiToken::findPlain($plain);
            if ($token === null) {
                return AuthenticateApiKey::refuse(401, __('API belirteci yok ya da geçersiz.'));
            }
            if ($token->app->site->domain !== $domain) {
                return AuthenticateApiKey::refuse(403, __('Bu belirteç başka bir uygulamanın.'));
            }
            if (! $token->can($ability)) {
                return AuthenticateApiKey::refuse(403, __('Bu belirteç yalnızca dağıtım içindir; Erişim ve API\'den Geliştirici yetkili bir belirteç oluşturun.'));
            }
            $app = $token->app;
            if ($feature !== null && ! $this->appHas($app, $feature)) {
                return AuthenticateApiKey::refuse(403, __('Paket bu özelliği içermiyor.'));
            }
            if (($limited = AuthenticateApiKey::limit('serfex-app-token:'.$token->id)) !== null) {
                return $limited;
            }
            $token->forceFill([
                'last_used_at' => now(),
                'last_used_ip' => $request->ip(),
                'last_used_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ])->save();
            $request->attributes->set('api_token', $token);
            $request->attributes->set('actor', 'API: '.$token->name);
        }
        $request->attributes->set('laravel_app', $app);

        return $next($request);
    }

    private function find(string $domain): ?LaravelApp
    {
        return LaravelApp::with('site.systemUser')->whereHas('site', fn ($q) => $q->where('domain', $domain))->first();
    }

    /**
     * Whether the package of the customer who owns $app has $feature
     * (applications without an owner have every feature).
     */
    private function appHas(LaravelApp $app, string $feature): bool
    {
        $app->loadMissing('site.systemUser.customer.package');
        $package = $app->site?->customer()?->package;

        return $package === null || $package->allows($feature);
    }
}
