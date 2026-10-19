<?php

namespace App\Http\Middleware;

use App\Models\LaravelApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The deploy API takes an application's API token as a bearer token; the
 * token works only for its own application.
 */
class AuthenticateDeployToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = LaravelApiToken::findPlain((string) $request->bearerToken());
        if ($token === null) {
            return response()->json(['message' => 'Missing or invalid API token.'], 401);
        }
        if ($token->app->site->domain !== $request->route('domain')) {
            return response()->json(['message' => 'This token belongs to another application.'], 403);
        }
        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'last_used_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ])->save();
        $request->attributes->set('laravel_app', $token->app);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
