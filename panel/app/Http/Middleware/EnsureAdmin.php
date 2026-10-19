<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server pages are for administrators. Other accounts (members of Laravel
 * applications) are sent to their applications.
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_admin) {
            return $next($request);
        }
        if ($request->isMethod('GET') && $request->routeIs('dashboard')) {
            return redirect()->route('laravel.index');
        }
        abort(403);
    }
}
