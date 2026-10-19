<?php

namespace App\Http\Controllers\Customers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Müşteri olarak giriş yap": an administrator sees the panel exactly as a
 * customer does, then returns to their own account.
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, Customer $customer): RedirectResponse
    {
        $admin = $request->user();
        $user = $customer->user;
        abort_unless($admin->is_admin && $user !== null && ! $user->is_admin, 403);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::KEY, $admin->id);

        return redirect()->route('dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = Impersonation::administrator();
        abort_if($admin === null, 403);
        $customerId = $request->user()?->customer_id;

        Auth::login($admin);
        $request->session()->forget(Impersonation::KEY);
        $request->session()->regenerate();

        return $customerId && Customer::withTrashed()->whereKey($customerId)->exists()
            ? redirect()->route('customers.show', $customerId)
            : redirect()->route('customers.index');
    }
}
