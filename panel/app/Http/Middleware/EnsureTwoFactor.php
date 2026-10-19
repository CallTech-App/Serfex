<?php

namespace App\Http\Middleware;

use App\Services\Security\TwoFactor;
use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an account that must use two-step login (Güvenlik → Giriş
 * güvenliği) to its security page until it sets it up. An administrator
 * viewing the panel as a customer is not stopped.
 */
class EnsureTwoFactor
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && ! Impersonation::active() && ! $request->routeIs('account.security', 'logout', 'impersonation.leave')
            && $this->twoFactor->mustSetUp($user)) {
            return redirect()->route('account.security')->with('two_factor_required', true);
        }

        return $next($request);
    }
}
