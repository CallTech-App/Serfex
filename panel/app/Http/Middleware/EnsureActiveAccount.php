<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a customer whose account was suspended or deleted, and an
 * administrator who was removed. An administrator viewing the panel as
 * a suspended customer stays.
 */
class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->removed_at !== null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('Hesabınız kaldırıldı.')]);
        }
        if ($user !== null && $user->customer_id !== null && ! Impersonation::active()) {
            $customer = $user->customer;
            if ($customer === null || $customer->isSuspended()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors(['email' => __(Impersonation::SUSPENDED)]);
            }
        }

        return $next($request);
    }
}
