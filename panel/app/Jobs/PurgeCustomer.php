<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Customers\Customers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes deleting a customer once the undo window has passed: their
 * sites go too when that was asked (with mail, DNS and Laravel
 * applications, each backed up first), otherwise the sites stay and serve again without an
 * owner. The login goes with the customer.
 */
class PurgeCustomer implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $customerId) {}

    public function handle(Customers $customers): void
    {
        $customer = Customer::onlyTrashed()->find($this->customerId);
        if ($customer === null || $customer->deleted_at->gt(now()->subSeconds(Customers::UNDO_SECONDS - 5))) {
            // Restored meanwhile, or restored and deleted again: the later
            // deletion's own job finishes it.
            return;
        }
        $accounts = SystemUser::where('customer_id', $customer->id)->get();
        foreach ($accounts as $account) {
            if ($account->uid === null && $account->sites()->doesntExist()) {
                // Reserved for the customer, never used on the server.
                $account->delete();

                continue;
            }
            if ($customer->delete_sites) {
                foreach (Site::where('system_user_id', $account->id)->where('status', '!=', SiteStatus::Deleting)->get() as $site) {
                    $site->update(['status' => SiteStatus::Deleting]);
                    DeleteSite::dispatch($site, removeUser: true, removeZone: true, removeMail: true, removeDatabases: true, backup: true);
                }
            }
            $account->update(['customer_id' => null]);
            if (! $customer->delete_sites) {
                // No longer suspended: the sites serve again.
                $customers->apply($customer, $account->fresh());
            }
        }
        $customer->user()->delete();
        $customer->forceDelete();
    }
}
