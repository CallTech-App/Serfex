<?php

namespace App\Http\Controllers\Api\Panel;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Models\Customer;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Php\PhpVersions;
use App\Services\Sites\SiteCreator;
use App\Support\Hostnames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SitesController extends ApiController
{
    /**
     * GET /api/v1/sites[?customer=<id>]
     */
    public function index(Request $request): JsonResponse
    {
        $sites = Site::with('systemUser.customer', 'certificate')
            ->when($request->filled('customer'), fn ($q) => $q->whereHas('systemUser', fn ($u) => $u->where('customer_id', (int) $request->query('customer'))))
            ->orderBy('domain')->get();

        return response()->json(['data' => $sites->map(fn (Site $s) => $this->site($s))->values()]);
    }

    public function show(string $domain): JsonResponse
    {
        return response()->json(['data' => $this->site($this->find($domain))]);
    }

    /**
     * POST /api/v1/sites {domain, php_version?, www?, customer_id?, account?}
     */
    public function store(Request $request, AgentClient $agent, Quotas $quotas, PhpVersions $php, SiteCreator $creator): JsonResponse
    {
        $request->merge(['domain' => Hostnames::normalize((string) $request->input('domain'))]);
        $data = $request->validate([
            'domain' => ['required', 'string', fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail('The domain is not valid.'), Rule::unique('sites', 'domain')],
            'php_version' => ['nullable', 'string', 'regex:/^\d\.\d$/'],
            'www' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'account' => ['nullable', 'string', 'regex:'.Hostnames::SYSTEM_USER_PATTERN, Rule::notIn(Hostnames::RESERVED_USERS)],
        ]);
        $customer = isset($data['customer_id']) ? Customer::with('package')->find($data['customer_id']) : null;
        $account = $customer?->username ?? ($data['account'] ?? Hostnames::suggestUser($data['domain']));
        $existing = SystemUser::where('name', $account)->first();
        if ($customer === null && $existing?->customer_id !== null && isset($data['account'])) {
            return $this->refuse('This account belongs to a customer; pass customer_id instead.');
        }
        $owner = $customer ?? $existing?->customer;
        if ($refusal = $quotas->refusal($owner, 'sites')) {
            return $this->refuse($refusal);
        }
        try {
            $installed = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            return $this->refuse('The server agent could not be reached: '.$e->reason, 503);
        }
        $allowed = $owner?->package ? $owner->package->phpVersionsFrom($installed) : $installed;
        $version = $data['php_version'] ?? $php->default($allowed);
        if ($version === null || ! in_array($version, $allowed, true)) {
            return $this->refuse('PHP '.($data['php_version'] ?? '').' is not available; installed and allowed: '.(implode(', ', $allowed) ?: 'none').'.');
        }

        $site = $creator->create($data['domain'], $account, $version, (bool) ($data['www'] ?? true), $customer, $owner);
        $this->audit($request, __('Siteler'), __(':domain oluşturuluyor.', ['domain' => $site->domain]), route('sites.show', $site, false));

        return response()->json(['data' => $this->site($site->fresh(['systemUser.customer', 'certificate']))], 202);
    }

    /**
     * DELETE /api/v1/sites/{domain}: the site with its DNS zone, mail and
     * databases, and its account when no other site uses it.
     */
    public function destroy(Request $request, string $domain): JsonResponse
    {
        $site = $this->find($domain);
        if ($site->status === SiteStatus::Deleting) {
            return response()->json(['data' => $this->site($site)], 202);
        }
        $keep = $request->boolean('keep_dns');
        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: true, removeZone: ! $keep, removeMail: true, removeDatabases: true);
        $this->audit($request, __('Siteler'), __(':domain siliniyor.', ['domain' => $site->domain]));

        return response()->json(['data' => $this->site($site)], 202);
    }

    private function find(string $domain): Site
    {
        return Site::with('systemUser.customer', 'certificate')->where('domain', mb_strtolower($domain))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function site(Site $site): array
    {
        $customer = $site->systemUser?->customer;

        return [
            'id' => $site->id,
            'domain' => $site->domain,
            'aliases' => $site->aliases ?? [],
            'status' => $site->status->value,
            'php_version' => $site->php_version,
            'web_server' => $site->web_server,
            'account' => $site->systemUser?->name,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->displayName()] : null,
            'https' => $site->hasHttps(),
            'last_error' => $site->last_error,
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }
}
