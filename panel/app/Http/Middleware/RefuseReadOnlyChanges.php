<?php

namespace App\Http\Middleware;

use App\Exceptions\ReadOnlyAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plain form posts (uploads, file saves, signing in as a customer) of a
 * read-only administrator are refused. Livewire's own routes pass: their
 * calls are checked one by one (ReadOnlyGuard). An X-Livewire header is
 * not enough, anyone can send one.
 */
class RefuseReadOnlyChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs('livewire.*') || $request->routeIs('logout', 'impersonation.leave')
            || ! $request->user()?->isReadOnly()) {
            return $next($request);
        }
        throw new ReadOnlyAccount;
    }
}
