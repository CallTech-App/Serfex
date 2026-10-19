<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hosting pages (sites, DNS, mail, SSL) are for administrators and for
 * customers, who see their own. Laravel team members are sent to their
 * applications.
 */
class EnsureHostingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->is_admin || $user?->isCustomer()) {
            return $next($request);
        }
        if ($request->isMethod('GET') && $request->routeIs('dashboard')) {
            return redirect()->route('laravel.index');
        }
        abort(403);
    }
}
