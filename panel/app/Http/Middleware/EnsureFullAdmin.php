<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Araçlar ve Ayarlar (panel settings, administrators, API keys,
 * notifications, the log and updates) and Servik AI's settings are for
 * full administrators only.
 */
class EnsureFullAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isFullAdmin()) {
            return $next($request);
        }
        abort(403);
    }
}
