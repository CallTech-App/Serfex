<?php

namespace App\Http\Controllers\Api\Panel;

use App\Enums\SiteStatus;
use App\Jobs\DeleteSite;
use App\Livewire\Sites\SiteIndex;
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

/**
 * Sites. Administrators see and change all of them; a customer their own,
 * created under their own account within their package.
 */
class SitesController extends ApiController
{
    /**
     * GET /api/v1/sites[?customer=<id>]
     */
    public function index(Request $request): JsonResponse
    {
        $sites = Site::visibleTo($this->user($request))->with('systemUser.customer', 'certificate')
            ->when($request->filled('customer'), fn ($q) => $q->whereHas('systemUser', fn ($u) => $u->where('customer_id', (int) $request->query('customer'))))
            ->orderBy('domain')->get();

        return response()->json(['data' => $sites->map(fn (Site $s) => $this->site($s))->values()]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        return response()->json(['data' => $this->site($this->findSite($request, $domain))]);
    }

    /**
     * POST /api/v1/sites {domain, php_version?, www?, customer_id?, account?}
     */
    public function store(Request $request, AgentClient $agent, Quotas $quotas, PhpVersions $php, SiteCreator $creator): JsonResponse
    {
        $user = $this->user($request);
        $request->merge(['domain' => Hostnames::normalize((string) $request->input('domain'))]);
        $data = $request->validate([
            'domain' => ['required', 'string', fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.')), Rule::unique('sites', 'domain')],
            'php_version' => ['nullable', 'string', 'regex:/^\d\.\d$/'],
            'www' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'account' => ['nullable', 'string', 'regex:'.Hostnames::SYSTEM_USER_PATTERN, Rule::notIn(Hostnames::RESERVED_USERS)],
        ], ['domain.unique' => __('Bu alan adı zaten bir siteye ait.')]);
        if ($user->isCustomer()) {
            // A customer's sites go under their own account.
            $data['customer_id'] = $user->customer_id;
            unset($data['account']);
        }
        $customer = isset($data['customer_id']) ? Customer::with('package')->find($data['customer_id']) : null;
        $account = $customer?->username ?? ($data['account'] ?? Hostnames::suggestUser($data['domain']));
        $existing = SystemUser::where('name', $account)->first();
        if ($customer === null && $existing?->customer_id !== null && isset($data['account'])) {
            return $this->refuse(__('Bu hesap bir müşterinin; customer_id ile müşteriyi belirtin.'));
        }
        $owner = $customer ?? $existing?->customer;
        if ($user->isCustomer() && SiteIndex::zoneTakenByOthers($data['domain'], $owner)) {
            return $this->refuse(__('Bu alan adı ya da üst alan adı başka bir hesaba ait.'));
        }
        if ($refusal = $quotas->refusal($owner, 'sites')) {
            return $this->refuse($refusal);
        }
        try {
            $installed = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException $e) {
            return $this->refuse(__('Sunucu ajanına ulaşılamadı: :reason', ['reason' => $e->reason]), 503);
        }
        $allowed = $owner?->package ? $owner->package->phpVersionsFrom($installed) : $installed;
        $version = $data['php_version'] ?? $php->default($allowed);
        if ($version === null || ! in_array($version, $allowed, true)) {
            return $this->refuse(__('PHP :version kullanılamıyor; kurulu ve izin verilenler: :allowed.', ['version' => $data['php_version'] ?? '', 'allowed' => implode(', ', $allowed) ?: '—']));
        }

        $site = $creator->create($data['domain'], $account, $version, (bool) ($data['www'] ?? true), $customer, $owner);
        $this->audit($request, __('Siteler'), __(':domain oluşturuluyor.', ['domain' => $site->domain]), route('sites.show', $site, false));

        return response()->json(['data' => $this->site($site->fresh(['systemUser.customer', 'certificate']))], 202);
    }

    /**
     * DELETE /api/v1/sites/{domain}[?keep_dns=1]: the site with its DNS zone,
     * mail and databases, and its account when no other site uses it.
     */
    public function destroy(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain);
        if ($site->status === SiteStatus::Deleting) {
            return response()->json(['data' => $this->site($site)], 202);
        }
        $keep = $request->boolean('keep_dns');
        $site->update(['status' => SiteStatus::Deleting]);
        DeleteSite::dispatch($site, removeUser: true, removeZone: ! $keep, removeMail: true, removeDatabases: true);
        $this->audit($request, __('Siteler'), __(':domain siliniyor.', ['domain' => $site->domain]));

        return response()->json(['data' => $this->site($site)], 202);
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
            'https_redirect' => (bool) $site->https_redirect,
            'certificate_expires_at' => $site->certificate?->not_after?->toIso8601String(),
            'disk_bytes' => $site->disk_bytes,
            'last_error' => $site->last_error,
            'created_at' => $site->created_at?->toIso8601String(),
        ];
    }
}
