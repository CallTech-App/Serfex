<?php

namespace App\Http\Controllers;

use App\Services\Customers\Quotas;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Genel Bakış: the server's state for administrators, the package and its
 * usage for a customer.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, Quotas $quotas): View
    {
        $user = $request->user();
        if ($user->is_admin) {
            return view('dashboard');
        }
        $customer = $user->customer()->with('package')->firstOrFail();

        return view('customers.dashboard', [
            'customer' => $customer,
            'report' => $quotas->report($customer),
        ]);
    }
}
