<?php

namespace App\Http\Middleware;

use App\Exceptions\ReadOnlyAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plain form posts (uploads, file saves, signing in as a customer) of a
 * read-only administrator are refused. Livewire updates pass: their
 * calls are checked one by one (ReadOnlyGuard).
 */
class RefuseReadOnlyChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->hasHeader('X-Livewire') || $request->routeIs('logout', 'impersonation.leave')
            || ! $request->user()?->isReadOnly()) {
            return $next($request);
        }
        throw new ReadOnlyAccount;
    }
}
