<?php

namespace App\Jobs;

use App\Enums\SiteStatus;
use App\Models\Customer;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Writes the PHP-FPM pools of sites again after something they inherit
 * changed: the functions customers' sites run without, or a package's
 * PHP limits. A site the server refuses keeps its previous pool; that is
 * logged, the site itself is left alone.
 */
class ApplySitePhp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * @param  list<int>|null  $siteIds  null: every customer's site
     */
    public function __construct(public ?array $siteIds = null) {}

    /**
     * Every site that belongs to a customer.
     */
    public static function forCustomers(): void
    {
        self::dispatch();
    }

    public static function forCustomer(Customer $customer): void
    {
        $ids = Site::whereHas('systemUser', fn ($u) => $u->where('customer_id', $customer->id))->pluck('id')->all();
        if ($ids !== []) {
            self::dispatch($ids);
        }
    }

    public function handle(AgentClient $agent): void
    {
        $query = Site::with('systemUser.customer.package', 'certificate')->where('status', SiteStatus::Active);
        $this->siteIds === null
            ? $query->whereHas('systemUser', fn ($u) => $u->whereNotNull('customer_id'))
            : $query->whereKey($this->siteIds);
        foreach ($query->orderBy('id')->get() as $site) {
            try {
                $agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
            } catch (AgentException $e) {
                Log::warning("PHP settings of {$site->domain} could not be applied: {$e->getMessage()}");
            }
        }
    }
}
