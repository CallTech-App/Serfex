<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for the request: the signed-in person's own choice,
 * else the server's default. Before signing in: the language picked on
 * the sign-in page (?lang=), else the server's default. The browser's
 * language is not used: a new server reads English until Turkish is
 * chosen at installation.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Locales::supports($chosen = $request->query('lang'))) {
            $request->session()->put('locale', $chosen);
        }
        $user = $request->user();
        if ($user !== null) {
            $locale = Locales::forUser($user);
        } else {
            $session = $request->hasSession() ? $request->session()->get('locale') : null;
            $locale = Locales::supports($session) ? $session : Locales::serverDefault();
        }
        Locales::apply($locale);

        return $next($request);
    }
}
