<?php

namespace App\Http\Middleware;

use App\Models\Package;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer's key reaches DNS, mail and backups only when their package
 * has the feature, as in the panel.
 */
class EnsureApiFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! $request->user()->allows($feature)) {
            return AuthenticateApiKey::refuse(403, __('Paketiniz :feature özelliğini içermiyor.', ['feature' => __(Package::FEATURES[$feature][1] ?? $feature)]));
        }

        return $next($request);
    }
}
