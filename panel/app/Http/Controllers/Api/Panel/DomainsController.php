<?php

namespace App\Http\Controllers\Api\Panel;

use App\Livewire\Sites\SiteDomainList;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Sites\SiteDomains;
use App\Support\Hostnames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A site's other names (Alan adları): subdomains with their own document
 * root, additional domains that open the same site, and redirects. As in
 * the panel, each change is tested and written to the server at once,
 * within the owner's package.
 */
class DomainsController extends ApiController
{
    public function __construct(private readonly SiteDomains $domains) {}

    /**
     * GET /api/v1/sites/{domain}/domains
     */
    public function index(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['subdomains', 'redirects']);

        return response()->json(['data' => $this->names($site)]);
    }

    /**
     * POST /api/v1/sites/{domain}/subdomains {name, root?}: "blog" or
     * "blog.example.com"; the root is relative to the site's folder
     * (default subdomains/<name>).
     */
    public function storeSubdomain(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['subdomains', 'redirects']);
        $data = $request->validate(['name' => ['required', 'string', 'max:253'], 'root' => ['nullable', 'string', 'max:200']], [], ['name' => __('ad')]);
        $label = Hostnames::normalize($data['name']);
        if (str_ends_with($label, '.'.$site->domain)) {
            $label = substr($label, 0, -strlen('.'.$site->domain));
        }
        $name = $label === '' ? '' : $label.'.'.$site->domain;
        if ($label === '' || str_contains($label, '/') || ! Hostnames::isValidDomain($name)) {
            return $this->refuse(__('Harf, rakam ve tire kullanın; örneğin blog ya da api.v2.'));
        }
        if ($problem = $this->domains->nameRefusal($site, $name, $this->user($request))) {
            return $this->refuse($problem);
        }
        $root = trim((string) ($data['root'] ?? ''), " /\t") ?: SiteDomains::defaultRoot($label);
        if ($problem = SiteDomains::rootProblem($root)) {
            return $this->refuse($problem);
        }
        if ($refusal = $this->domains->limitRefusal($site, 'subdomains')) {
            return $this->refuse($refusal);
        }
        try {
            $this->domains->addSubdomain($site, $name, $root);
        } catch (AgentException $e) {
            return $this->refuse(__('Sunucu alt alan adını ekleyemedi: :for_people', ['for_people' => $e->forPeople()]), 502);
        }
        Quotas::forget();
        $this->audit($request, __('Alan adları · :domain', ['domain' => $site->domain]), __(':name eklendi.', ['name' => $name]), route('sites.domains', $site, false));

        return response()->json(['data' => $this->names($site->fresh(['subdomains', 'redirects']))], 201);
    }

    /**
     * POST /api/v1/sites/{domain}/aliases {name, dns?}: another domain that
     * opens the same site; dns (default true) creates its DNS zone.
     */
    public function storeAlias(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['subdomains', 'redirects']);
        $data = $request->validate(['name' => ['required', 'string', 'max:253'], 'dns' => ['nullable', 'boolean']], [], ['name' => __('ad')]);
        $name = Hostnames::normalize($data['name']);
        if ($problem = $this->domains->nameRefusal($site, $name, $this->user($request))) {
            return $this->refuse($problem);
        }
        if ($refusal = $this->domains->limitRefusal($site, 'aliases')) {
            return $this->refuse($refusal);
        }
        try {
            $this->domains->addAlias($site, $name, ($data['dns'] ?? true) && $this->user($request)->allows('dns'));
        } catch (AgentException $e) {
            return $this->refuse(__('Sunucu ek alan adını ekleyemedi: :for_people', ['for_people' => $e->forPeople()]), 502);
        }
        Quotas::forget();
        $this->audit($request, __('Alan adları · :domain', ['domain' => $site->domain]), __(':name artık :domain sitesini açıyor.', ['name' => $name, 'domain' => $site->domain]), route('sites.domains', $site, false));

        return response()->json(['data' => $this->names($site->fresh(['subdomains', 'redirects']))], 201);
    }

    /**
     * POST /api/v1/sites/{domain}/redirects {source, target, code?, keep_path?, www?, dns?}:
     * a source starting with / redirects that path of the site; otherwise
     * it is a domain (with www. unless www is false) sent to the target.
     */
    public function storeRedirect(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['subdomains', 'redirects']);
        $data = $request->validate([
            'source' => ['required', 'string', 'max:253'],
            'target' => ['required', 'string', 'max:2000'],
            'code' => ['nullable', 'integer'],
            'keep_path' => ['nullable', 'boolean'],
            'www' => ['nullable', 'boolean'],
            'dns' => ['nullable', 'boolean'],
        ], [], ['source' => __('kaynak'), 'target' => __('hedef')]);
        $kind = str_starts_with(trim($data['source']), '/') ? SiteRedirect::PATH : SiteRedirect::DOMAIN;
        $target = trim($data['target']);
        $code = (int) ($data['code'] ?? 301);
        $keepPath = (bool) ($data['keep_path'] ?? true);
        $withWww = (bool) ($data['www'] ?? true);
        if (! isset(SiteRedirect::CODES[$code])) {
            return $this->refuse(__('Yönlendirme kodu :codes olabilir.', ['codes' => implode(', ', array_keys(SiteRedirect::CODES))]));
        }
        if ($problem = SiteDomainList::targetProblem($target, $kind, $keepPath)) {
            return $this->refuse($problem);
        }
        $user = $this->user($request);
        if ($kind === SiteRedirect::DOMAIN) {
            $source = Hostnames::normalize($data['source']);
            if (str_starts_with($source, 'www.')) {
                $source = substr($source, 4);
                $withWww = true;
            }
            if (($problem = $this->domains->nameRefusal($site, $source, $user))
                || ($withWww && ($problem = $this->domains->nameRefusal($site, 'www.'.$source, $user)))) {
                return $this->refuse($problem);
            }
            if (parse_url($target, PHP_URL_HOST) === $source) {
                return $this->refuse(__('Hedef, yönlendirilen alan adının kendisi olamaz.'));
            }
            if ($refusal = $this->domains->limitRefusal($site, 'aliases')) {
                return $this->refuse($refusal);
            }
        } else {
            $source = '/'.trim(trim($data['source']), '/');
            if ($problem = SiteDomainList::pathProblem($source)) {
                return $this->refuse($problem);
            }
            if ($site->redirects->where('kind', SiteRedirect::PATH)->contains('source', $source)) {
                return $this->refuse(__('Bu yol için zaten bir yönlendirme var.'));
            }
        }
        try {
            $this->domains->addRedirect($site, [
                'kind' => $kind,
                'source' => $source,
                'with_www' => $kind === SiteRedirect::DOMAIN && $withWww,
                'target' => $target,
                'code' => $code,
                'keep_path' => $keepPath,
            ], ($data['dns'] ?? true) && $user->allows('dns'));
        } catch (AgentException $e) {
            return $this->refuse(__('Sunucu yönlendirmeyi ekleyemedi: :for_people', ['for_people' => $e->forPeople()]), 502);
        }
        Quotas::forget();
        $this->audit($request, __('Alan adları · :domain', ['domain' => $site->domain]), __('Yönlendirme eklendi.'), route('sites.domains', $site, false));

        return response()->json(['data' => $this->names($site->fresh(['subdomains', 'redirects']))], 201);
    }

    /**
     * DELETE /api/v1/sites/{domain}/domains?name=<name>: a subdomain, an
     * additional domain or a redirect (by its source), whichever has it.
     */
    public function destroy(Request $request, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['subdomains', 'redirects']);
        $name = trim((string) $request->query('name', $request->input('name', '')));
        $host = Hostnames::normalize($name);
        if (! str_starts_with($name, '/') && ! str_contains($host, '.') && $host !== '') {
            $host .= '.'.$site->domain;
        }
        [$kind, $key] = match (true) {
            str_starts_with($name, '/') => ['redirect', $site->redirects->where('kind', SiteRedirect::PATH)->firstWhere('source', '/'.trim($name, '/'))?->id],
            ($sub = $site->subdomains->firstWhere('name', $host)) !== null => ['subdomain', $sub->id],
            in_array($host, $site->aliases ?? [], true) => ['alias', $host],
            default => ['redirect', $site->redirects->where('kind', SiteRedirect::DOMAIN)->first(fn (SiteRedirect $r) => $r->source === $host || ($r->with_www && 'www.'.$r->source === $host))?->id],
        };
        if ($key === null) {
            $this->missing(__(':name bu sitenin adlarından biri değil.', ['name' => $name]));
        }
        try {
            $this->domains->remove($site, $kind, $key);
        } catch (AgentException $e) {
            return $this->refuse(__('Sunucu kaldıramadı: :for_people', ['for_people' => $e->forPeople()]), 502);
        }
        Quotas::forget();
        $this->audit($request, __('Alan adları · :domain', ['domain' => $site->domain]), __(':name kaldırıldı.', ['name' => $name]), route('sites.domains', $site, false));

        return response()->json(['data' => $this->names($site->fresh(['subdomains', 'redirects']))]);
    }

    /**
     * Served over HTTPS, waiting for DNS or its certificate (as the page
     * shows it).
     *
     * @return array{text: string, tone: string, hint: string}
     */
    private function state(Site $site, string $name): array
    {
        $state = $this->domains->state($site, $name);

        return ['text' => $state['badge']->label(), 'tone' => $state['badge']->tone(), 'hint' => $state['hint']];
    }

    /**
     * @return array<string, mixed>
     */
    private function names(Site $site): array
    {
        return [
            'site' => $site->domain,
            'subdomains' => $site->subdomains->sortBy('name')->map(fn (Subdomain $s) => [
                'name' => $s->name,
                'root' => $s->web_root,
                'state' => $this->state($site, $s->name),
            ])->values(),
            'aliases' => collect($site->aliases ?? [])->sort()->map(fn (string $a) => [
                'name' => $a,
                'state' => $this->state($site, $a),
            ])->values(),
            'redirects' => $site->redirects->map(fn (SiteRedirect $r) => [
                'kind' => $r->kind,
                'source' => $r->source,
                'www' => (bool) $r->with_www,
                'target' => $r->target,
                'code' => $r->code,
                'keep_path' => (bool) $r->keep_path,
            ])->values(),
        ];
    }
}
