<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\User;
use App\Services\Settings\ApiKeys;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel API takes an API key as a bearer token. The request then runs
 * as the key's person, within what that person may do in the panel:
 *
 * - a panel key (API anahtarları) only while its maker is a full
 *   administrator;
 * - a command line key as its person: administrators everything their
 *   role allows (read-only ones may only read), customers their own
 *   hosting within their package, Laravel team members only their
 *   applications.
 *
 * A read key may only GET. $who is the route's audience: "admin" for
 * administrators only, "account" for administrators and customers,
 * "any" for every signed-in person.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeys $keys) {}

    public function handle(Request $request, Closure $next, string $who = 'account'): Response
    {
        $key = $this->keys->find((string) $request->bearerToken());
        if ($key === null || ! $key->isUsable()) {
            return self::refuse(401, $key?->revoked_at ? __('Bu API anahtarı iptal edildi.') : ($key ? __('Bu API anahtarının süresi doldu.') : __('API anahtarı yok ya da geçersiz.')));
        }
        if (! $key->allowsIp($request->ip())) {
            return self::refuse(403, __('Bu API anahtarı :ip adresinden kullanılamaz.', ['ip' => $request->ip()]));
        }
        $user = $key->user;
        if (($refusal = self::userRefusal($key, $user)) !== null) {
            return self::refuse(403, $refusal);
        }
        if (! $request->isMethodSafe() && ($key->scope !== 'write' || $user->isReadOnly())) {
            return self::refuse(403, $user->isReadOnly() ? __('Salt okunur yöneticiler değişiklik yapamaz.') : __('Bu API anahtarı salt okunur.'));
        }
        if ($who === 'admin' && ! $user->is_admin) {
            return self::refuse(403, __('Bu işlem yalnızca yöneticiler içindir.'));
        }
        if ($who === 'account' && ! $user->is_admin && ! $user->isCustomer()) {
            return self::refuse(403, __('Hesabınız yalnızca eklendiğiniz Laravel uygulamalarında çalışabilir.'));
        }
        if (($limited = self::limit('servik-api:'.$key->id)) !== null) {
            return $limited;
        }

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute()) || $key->last_used_ip !== $request->ip()) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        Auth::setUser($user);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /**
     * Why $user can no longer use $key, or null.
     */
    public static function userRefusal(ApiKey $key, ?User $user): ?string
    {
        if ($user === null || $user->removed_at !== null) {
            return __('Bu API anahtarının sahibi artık paneli kullanamıyor.');
        }
        if (! $key->isCli() && ! $user->isFullAdmin()) {
            return __('Bu API anahtarını oluşturan yönetici artık API\'yı kullanamıyor.');
        }
        if ($user->customer_id !== null && ! $user->is_admin && ($user->customer === null || $user->customer->isSuspended())) {
            return __(Impersonation::SUSPENDED);
        }

        return null;
    }

    /**
     * Counts a request against $limit; the refusal once it is used up.
     */
    public static function limit(string $limit): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($limit, ApiKeys::PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($limit);

            return self::refuse(429, __('Çok fazla istek; :seconds saniye sonra yeniden deneyin.', ['seconds' => $seconds]))
                ->header('Retry-After', (string) $seconds);
        }
        RateLimiter::hit($limit, 60);

        return null;
    }

    public static function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    public static function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }
}
