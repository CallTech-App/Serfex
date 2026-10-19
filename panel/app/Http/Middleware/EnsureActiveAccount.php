<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a customer whose account was suspended or deleted. An
 * administrator viewing the panel as that customer stays.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->customer_id !== null && ! Impersonation::active()) {
            $customer = $user->customer;
            if ($customer === null || $customer->isSuspended()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => Impersonation::SUSPENDED]);
            }
        }

        return $next($request);
    }
}
