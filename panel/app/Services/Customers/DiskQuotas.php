<?php

namespace App\Services\Customers;

use App\Enums\MailDomainStatus;
use App\Jobs\SyncMailDomain;
use App\Models\Customer;
use App\Models\MailDomain;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Enforces customers' disk limits with the file system's user quotas
 * (the agent's quota.* methods), so nothing can be written past the
 * package, whether it arrives by the file manager, SFTP, PHP or Git.
 *
 * A customer's limit covers their files, mail, databases and Laravel
 * backups (Quotas::usage). Only files are owned by the customer's Linux
 * users, so each user gets what their files use now plus what is left of
 * the whole limit: for the usual customer with one account that is the
 * limit minus mail, databases and backups. When the limit is reached,
 * every account is held at what it uses (writes fail with "Disk quota
 * exceeded") and the customer's mail domains defer incoming mail with
 * "mailbox full" until room is made. Limits are recalculated every five
 * minutes (serfex:disk-quota) and right after anything that changes them.
 *
 * Accounts without a customer, and customers with an unlimited disk, get
 * no limit. Where quotas cannot be used (another file system, a kernel
 * without quota support), or when an administrator chose warnings only,
 * the limit stays the warning it was before.
 */
class DiskQuotas
{
    /** The agent's last quota.status, with checked_at. */
    public const STATUS = 'disk_quota.status';

    /** Whether an administrator wants limits enforced (default yes). */
    public const ENFORCE = 'disk_quota.enforce';

    /** Set while quotas are being turned on: when it started. */
    public const ENABLING = 'disk_quota.enabling';

    /** Why turning quotas on failed last time, if it did. */
    public const ERROR = 'disk_quota.error';

    public function __construct(private readonly AgentClient $agent, private readonly Quotas $quotas) {}

    /**
     * The last known status: state (on, off, unsupported or unknown),
     * reason, detail, mount, device, fstype, enabled_at, checked_at.
     *
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return (array) Setting::get(self::STATUS, ['state' => 'unknown']);
    }

    /**
     * Asks the agent and remembers the answer.
     *
     * @return array<string, mixed>
     */
    public function refreshStatus(): array
    {
        $status = (array) $this->agent->call('quota.status') + ['checked_at' => now()->toIso8601String()];
        Setting::put(self::STATUS, $status);

        return $status;
    }

    public function wanted(): bool
    {
        return (bool) Setting::get(self::ENFORCE, true);
    }

    /**
     * True when limits are enforced on this server now.
     */
    public function enforcing(): bool
    {
        return $this->wanted() && ($this->status()['state'] ?? null) === 'on';
    }

    /**
     * True while quotas are being turned on (a job that died is forgotten
     * after 35 minutes).
     */
    public function enabling(): bool
    {
        $since = Setting::get(self::ENABLING);

        return $since !== null && Carbon::parse($since)->gt(now()->subMinutes(35));
    }

    /**
     * Turns quotas on for the file system holding /home (can take minutes:
     * packages are installed and every file is counted), then applies the
     * limits. A file system that cannot have quotas comes back in the
     * status; a failure throws.
     *
     * @return array<string, mixed>
     */
    public function enable(): array
    {
        Setting::put(self::ENABLING, now()->toIso8601String());
        try {
            $status = (array) $this->agent->call('quota.enable', [], config('serfex.agent.component_timeout'));
            Setting::put(self::STATUS, $status + ['checked_at' => now()->toIso8601String()]);
            Setting::put(self::ERROR, null);
            Setting::put(self::ENFORCE, true);
        } catch (AgentException $e) {
            Setting::put(self::ERROR, $e->reason ?: $e->getMessage());
            throw $e;
        } finally {
            Setting::put(self::ENABLING, null);
        }
        $this->sync();

        return $status;
    }

    /**
     * Enforces limits or keeps them warnings only, and applies that at once.
     */
    public function setWanted(bool $enforce): void
    {
        Setting::put(self::ENFORCE, $enforce);
        $this->sync();
    }

    /**
     * Brings every account's limit, and every customer's full state, in
     * line with usage and limits. $only limits the work to one customer
     * (after their package or limit changed) and skips asking the agent
     * for its status.
     */
    public function sync(?Customer $only = null): void
    {
        $status = $only === null ? $this->refreshStatus() : $this->status();
        $enforce = $this->wanted() && ($status['state'] ?? null) === 'on';

        $accounts = SystemUser::whereNotNull('uid')
            ->when($only, fn ($q) => $q->where('customer_id', $only->id))
            ->with('customer.package')->get();

        if (! $enforce) {
            // Limits set earlier come off; nothing is refused any more.
            $limited = $accounts->filter(fn (SystemUser $a) => $a->quota_limit_bytes !== null);
            if ($limited->isNotEmpty() && ($status['state'] ?? null) === 'on') {
                $this->agent->call('quota.set', ['limits' => $limited->mapWithKeys(fn (SystemUser $a) => [$a->name => 0])->all()], config('serfex.agent.job_timeout'));
            }
            SystemUser::whereIn('id', $limited->pluck('id'))->update(['quota_limit_bytes' => null]);
            Customer::whereNotNull('disk_full_at')->when($only, fn ($q) => $q->whereKey($only->id))->get()
                ->each(fn (Customer $c) => $this->markFull($c, false));
            Quotas::forget();

            return;
        }

        $report = $accounts->isEmpty() ? [] : (array) $this->agent->call('quota.report', ['users' => $accounts->pluck('name')->values()->all()], config('serfex.agent.job_timeout'));
        // What the file system counts is what it enforces.
        foreach ($accounts as $account) {
            if (isset($report[$account->name])) {
                $account->update(['disk_bytes' => (int) ((array) $report[$account->name])['bytes'], 'disk_measured_at' => now()]);
            }
        }

        $limits = [];
        foreach ($accounts->groupBy(fn (SystemUser $a) => (int) $a->customer_id) as $customerId => $group) {
            $customer = $customerId ? $group->first()->customer : null;
            $limit = $customer?->diskLimit();
            $left = null;
            if ($customer !== null && $limit !== null) {
                $used = (int) $this->quotas->usage($customer)['disk'];
                $left = max(0, $limit - $used);
                $this->markFull($customer, $used >= $limit);
            } elseif ($customer !== null) {
                $this->markFull($customer, false);
            }
            foreach ($group as $account) {
                if (! isset($report[$account->name])) {
                    continue; // not on the server (yet)
                }
                $now = (array) $report[$account->name];
                // At least one byte: 0 would mean "no limit" to the agent.
                $want = $left === null ? 0 : max(1, (int) $now['bytes'] + $left);
                if (self::kib($want) !== self::kib((int) $now['limit'])) {
                    $limits[$account->name] = $want;
                }
                if ($account->quota_limit_bytes !== ($want ?: null)) {
                    $account->update(['quota_limit_bytes' => $want ?: null]);
                }
            }
        }
        if ($limits !== []) {
            $this->agent->call('quota.set', ['limits' => $limits], config('serfex.agent.job_timeout'));
        }
        Quotas::forget();
    }

    /**
     * Customers whose disk is full while limits are enforced: their files
     * cannot grow and their incoming mail waits.
     *
     * @return Collection<int, Customer>
     */
    public function fullCustomers(): Collection
    {
        return $this->enforcing() ? Customer::whereNotNull('disk_full_at')->orderBy('disk_full_at')->get() : collect();
    }

    /**
     * True when quotas were on and are not any more (a restart into a
     * kernel without the quota module, someone ran quotaoff) although
     * an administrator wants them.
     */
    public function broken(): bool
    {
        $status = $this->status();

        return $this->wanted() && ($status['state'] ?? null) !== 'on' && ! empty($status['enabled_at']);
    }

    /**
     * Records whether the customer's disk is full and, when that changed,
     * re-sends their mail domains so incoming mail is refused or taken
     * again.
     */
    private function markFull(Customer $customer, bool $full): void
    {
        if ($full === ($customer->disk_full_at !== null)) {
            return;
        }
        $customer->update(['disk_full_at' => $full ? now() : null]);
        $siteIds = Site::whereIn('system_user_id', SystemUser::where('customer_id', $customer->id)->select('id'))->pluck('id');
        MailDomain::whereIn('site_id', $siteIds)->where('status', '!=', MailDomainStatus::Deleting)->get()
            ->each(function (MailDomain $domain) {
                $domain->update(['status' => MailDomainStatus::Syncing]);
                SyncMailDomain::dispatch($domain);
            });
    }

    private static function kib(int $bytes): int
    {
        return $bytes === 0 ? 0 : max(1, intdiv($bytes + 1023, 1024));
    }
}
