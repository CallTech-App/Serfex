<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API answers in the language the caller asks for with
 * Accept-Language (the servik command line sends its own), else in
 * English, so scripts see the same messages on every server.
 */
class ApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        Locales::apply(Locales::fromBrowser($request) ?? 'en');

        return $next($request);
    }
}
