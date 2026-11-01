<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Search engines never list the panel, its sign-in page or anything it
 * serves, nor keep a copy or a snippet of it. serfex-web sends the same
 * header; this one holds when the panel is reached some other way.
 */
class NoIndex
{
    public const VALUE = 'noindex, nofollow, noarchive, nosnippet';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::VALUE);

        return $response;
    }
}
