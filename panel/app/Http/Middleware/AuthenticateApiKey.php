<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\Settings\ApiKeys;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel API takes an API key as a bearer token. The request then runs
 * as the full administrator who made the key; a read key may only GET.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeys $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->keys->find((string) $request->bearerToken());
        if ($key === null || ! $key->isUsable()) {
            return $this->refuse(401, $key?->revoked_at ? 'This API key was revoked.' : ($key ? 'This API key has expired.' : 'Missing or invalid API key.'));
        }
        if (! $key->allowsIp($request->ip())) {
            return $this->refuse(403, 'This API key may not be used from '.$request->ip().'.');
        }
        $user = $key->user;
        if ($user === null || $user->removed_at !== null || ! $user->isFullAdmin()) {
            return $this->refuse(403, 'The administrator who made this API key can no longer use the API.');
        }
        if ($key->scope !== 'write' && ! $request->isMethodSafe()) {
            return $this->refuse(403, 'This API key is read-only.');
        }
        $limit = 'servik-api:'.$key->id;
        if (RateLimiter::tooManyAttempts($limit, ApiKeys::PER_MINUTE)) {
            return $this->refuse(429, 'Too many requests; try again in '.RateLimiter::availableIn($limit).' seconds.')
                ->header('Retry-After', (string) RateLimiter::availableIn($limit));
        }
        RateLimiter::hit($limit, 60);

        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute()) || $key->last_used_ip !== $request->ip()) {
            $key->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }
        Auth::setUser($user);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    private function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    public static function key(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }
}
