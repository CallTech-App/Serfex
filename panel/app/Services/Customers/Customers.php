<?php

namespace App\Services\Customers;

use App\Enums\MailDomainStatus;
use App\Enums\SiteStatus;
use App\Jobs\ProvisionSite;
use App\Jobs\PurgeCustomer;
use App\Jobs\SyncDatabaseUsers;
use App\Jobs\SyncLaravelProcesses;
use App\Jobs\SyncMailDomain;
use App\Models\Customer;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Support\Hostnames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creating, suspending and deleting customers, and moving accounts (with
 * their sites) to and from them.
 */
class Customers
{
    /** How long a deleted customer can be brought back, in seconds. */
    public const UNDO_SECONDS = 600;

    /**
     * Creates the customer with a login that waits for its invitation to be
     * accepted. Returns the customer and the invitation link's token.
     *
     * @param  array{company?: ?string, name: string, email: string, phone?: ?string, username: string, package_id: int}  $data
     * @return array{0: Customer, 1: string}
     */
    public function create(array $data): array
    {
        $invite = Str::random(48);
        $customer = DB::transaction(function () use ($data, $invite) {
            $customer = Customer::create([
                'package_id' => $data['package_id'],
                'company' => $data['company'] ?? null,
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'username' => $data['username'],
            ]);
            User::forceCreate([
                'customer_id' => $customer->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make(Str::random(40)),
                'is_admin' => false,
                'invite_token' => hash('sha256', $invite),
                'invited_at' => now(),
            ]);
            // Sites created later for the customer go under this account.
            $account = SystemUser::firstOrCreate(['name' => $data['username']]);
            if ($account->customer_id === null) {
                $account->update(['customer_id' => $customer->id]);
            }

            return $customer;
        });

        return [$customer, $invite];
    }

    /**
     * A new invitation link: the customer sets their password with it.
     */
    public function newInvite(Customer $customer): string
    {
        $invite = Str::random(48);
        $customer->user?->forceFill(['invite_token' => hash('sha256', $invite), 'invited_at' => now()])->save();

        return $invite;
    }

    /**
     * The account new sites of the customer go under: theirs, created on
     * first use.
     */
    public function account(Customer $customer): SystemUser
    {
        $account = SystemUser::firstOrCreate(['name' => $customer->username], ['customer_id' => $customer->id]);
        if ($account->customer_id === null) {
            $account->update(['customer_id' => $customer->id]);
        }

        return $account;
    }

    /**
     * A free Linux account name based on $from.
     */
    public function suggestUsername(string $from): string
    {
        // "Hızlı Kargo A.Ş." becomes "hizlikargo".
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($from))) ?: []));
        $base = Hostnames::suggestUser(implode('', array_slice($words, 0, 2)).'.x');
        $name = $base;
        for ($i = 2; SystemUser::where('name', $name)->exists() || Customer::withTrashed()->where('username', $name)->exists(); $i++) {
            $name = Str::limit($base, 29, '').$i;
        }

        return $name;
    }

    public function suspend(Customer $customer, ?string $reason = null): void
    {
        if ($customer->suspended_at !== null) {
            return;
        }
        $customer->update(['suspended_at' => now(), 'suspend_reason' => $reason]);
        $this->apply($customer);
    }

    public function unsuspend(Customer $customer): void
    {
        if ($customer->suspended_at === null) {
            return;
        }
        $customer->update(['suspended_at' => null, 'suspend_reason' => null]);
        $this->apply($customer);
    }

    /**
     * Moves an account and all its sites to the customer.
     */
    public function attach(Customer $customer, SystemUser $account): void
    {
        $before = $account->customer?->isSuspended() ?? false;
        $account->update(['customer_id' => $customer->id]);
        if ($before !== $customer->isSuspended()) {
            $this->apply($customer, $account);
        }
    }

    /**
     * Takes an account (and its sites) away from the customer; it then
     * belongs to no one and only administrators see it.
     */
    public function detach(Customer $customer, SystemUser $account): void
    {
        if ($account->customer_id !== $customer->id) {
            return;
        }
        $account->update(['customer_id' => null]);
        if ($customer->isSuspended()) {
            $this->applyAccount($account->fresh());
        }
    }

    /**
     * Deletes the customer after the undo window. Until then their login
     * stops working and their sites show the suspension page. With
     * $withSites, their sites (with mail, DNS and Laravel applications)
     * are deleted too; otherwise they stay on the server without an owner.
     */
    public function delete(Customer $customer, bool $withSites): void
    {
        $customer->update(['delete_sites' => $withSites]);
        $customer->delete();
        $this->apply($customer);
        PurgeCustomer::dispatch($customer->id)->delay(now()->addSeconds(self::UNDO_SECONDS));
    }

    public function restore(Customer $customer): bool
    {
        if (! $customer->trashed()) {
            return false;
        }
        $customer->restore();
        $customer->update(['delete_sites' => null]);
        $this->apply($customer);

        return true;
    }

    /**
     * Makes the server follow the customer's state: every site, mail
     * domain, Laravel application and database user is re-applied,
     * suspended or not.
     */
    public function apply(Customer $customer, ?SystemUser $only = null): void
    {
        $accounts = $only ? collect([$only]) : SystemUser::where('customer_id', $customer->id)->get();
        foreach ($accounts as $account) {
            $this->applyAccount($account);
        }
    }

    private function applyAccount(SystemUser $account): void
    {
        SyncDatabaseUsers::dispatch($account->id);
        foreach (Site::with('laravelApp')->where('system_user_id', $account->id)->get() as $site) {
            if ($site->status === SiteStatus::Deleting) {
                continue;
            }
            if ($site->status === SiteStatus::Active) {
                $site->update(['status' => SiteStatus::Provisioning]);
            }
            ProvisionSite::dispatch($site);
            if ($site->laravelApp !== null) {
                SyncLaravelProcesses::dispatch($site->laravelApp);
            }
            $mail = MailDomain::where('site_id', $site->id)->where('status', '!=', MailDomainStatus::Deleting)->first();
            if ($mail !== null) {
                $mail->update(['status' => MailDomainStatus::Syncing]);
                SyncMailDomain::dispatch($mail);
            }
        }
    }
}
