<?php

namespace App\Services\Sites;

use App\Enums\CertificateStatus;
use App\Enums\SiteStatus;
use App\Models\Certificate;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Dns\Zones;
use App\Services\Ssl\Certificates;
use App\Services\Ssl\DnsCheck;
use App\Support\Badge;
use App\Support\Hostnames;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The names a site answers to besides its domain: subdomains, additional
 * domains (aliases) and redirected domains, plus path redirects.
 *
 * A change is written to the server at once (site.apply, which tests the
 * configuration first); when the agent refuses, the panel change is rolled
 * back. A new name then gets address records when its zone is on this
 * server, and the site's Let's Encrypt certificate is reissued to cover
 * it once its DNS points here.
 */
class SiteDomains
{
    /** How long a removal can be undone, in seconds. */
    public const UNDO_SECONDS = 600;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Zones $zones,
        private readonly Certificates $certificates,
        private readonly DnsCheck $dns,
        private readonly Quotas $quotas,
    ) {}

    /**
     * Who already answers to $name, in words, or null when it is free.
     */
    public function taken(string $name): ?string
    {
        if (Site::where('domain', $name)->exists()) {
            return __(':name zaten bir site.', ['name' => $name]);
        }
        if ($site = Site::whereJsonContains('aliases', $name)->first()) {
            return __(':name zaten :domain sitesinin ek alan adı.', ['name' => $name, 'domain' => $site->domain]);
        }
        if ($sub = Subdomain::with('site')->where('name', $name)->first()) {
            return __(':name zaten :domain sitesinin alt alan adı.', ['name' => $name, 'domain' => $sub->site->domain]);
        }
        if ($redirect = $this->redirectFor($name)) {
            return __(':name zaten :domain sitesinde yönlendiriliyor.', ['name' => $name, 'domain' => $redirect->site->domain]);
        }

        return null;
    }

    /**
     * The site that answers to $name in any way, if one does.
     */
    public function siteFor(string $name): ?Site
    {
        return Site::where('domain', $name)->first()
            ?? Site::whereJsonContains('aliases', $name)->first()
            ?? Subdomain::where('name', $name)->first()?->site
            ?? $this->redirectFor($name)?->site;
    }

    private function redirectFor(string $name): ?SiteRedirect
    {
        $bare = str_starts_with($name, 'www.') ? substr($name, 4) : null;

        return SiteRedirect::with('site')->where('kind', SiteRedirect::DOMAIN)
            ->where(fn ($q) => $q->where('source', $name)
                ->when($bare !== null, fn ($q) => $q->orWhere(fn ($q) => $q->where('source', $bare)->where('with_www', true))))
            ->first();
    }

    /**
     * Why $user may not add $name to $site, or null. Names must be free;
     * a customer may not use a name inside another account's zone.
     */
    public function nameRefusal(Site $site, string $name, User $user): ?string
    {
        if (! Hostnames::isValidDomain($name)) {
            return __('Geçerli bir alan adı girin, örneğin ornek.com.tr.');
        }
        if ($taken = $this->taken($name)) {
            return $taken;
        }
        if ($user->isCustomer()) {
            $labels = explode('.', $name);
            $names = [];
            for ($i = 0; $i < count($labels) - 1; $i++) {
                $names[] = implode('.', array_slice($labels, $i));
            }
            $customer = $user->customer;
            if (DnsZone::with('site.systemUser')->whereIn('name', $names)->get()
                ->contains(fn (DnsZone $zone) => $zone->site === null || ! $customer->owns($zone->site))) {
                return __('Bu alan adı ya da üst alan adı başka bir hesaba ait.');
            }
        }

        return null;
    }

    /**
     * Why the site's owner may not add one more $kind (subdomains or
     * aliases), or null.
     */
    public function limitRefusal(Site $site, string $kind): ?string
    {
        return $this->quotas->refusal($site->customer(), $kind);
    }

    /**
     * Writes the site to the server now, so a refusal comes back to the
     * person who made the change.
     *
     * @throws AgentException
     */
    public function apply(Site $site): void
    {
        $site->unsetRelation('subdomains')->unsetRelation('redirects');
        $site->load('systemUser.customer', 'certificate', 'subdomains', 'redirects');
        $result = $this->agent->call('site.apply', $site->agentSpec(), config('servik.agent.job_timeout'));
        $site->update([
            'status' => SiteStatus::Active,
            'last_error' => null,
            'document_root' => $result['document_root'] ?? $site->document_root,
            'provisioned_at' => $site->provisioned_at ?? now(),
        ]);
    }

    /**
     * Runs $change, then writes the site to the server; when the server
     * refuses, the change is rolled back.
     *
     * @template T
     *
     * @param  callable(): T  $change
     * @return T
     *
     * @throws AgentException
     */
    public function change(Site $site, callable $change): mixed
    {
        return DB::transaction(function () use ($site, $change) {
            $result = $change();
            $this->apply($site);

            return $result;
        });
    }

    /**
     * @throws AgentException
     */
    public function addSubdomain(Site $site, string $name, string $webRoot): Subdomain
    {
        $subdomain = $this->change($site, fn () => $site->subdomains()->create(['name' => $name, 'web_root' => $webRoot]));
        $this->published($site, [$name]);

        return $subdomain;
    }

    /**
     * @throws AgentException
     */
    public function addAlias(Site $site, string $name, bool $createZone = false): void
    {
        $this->change($site, fn () => $site->update(['aliases' => [...($site->aliases ?? []), $name]]));
        $this->published($site, [$name], $createZone);
    }

    /**
     * @param  array{kind: string, source: string, with_www?: bool, target: string, code: int, keep_path: bool}  $values
     *
     * @throws AgentException
     */
    public function addRedirect(Site $site, array $values, bool $createZone = false): SiteRedirect
    {
        $redirect = $this->change($site, fn () => $site->redirects()->create($values));
        if ($redirect->isDomain()) {
            $this->published($site, $redirect->hosts(), $createZone);
        }

        return $redirect;
    }

    /**
     * @param  array{target: string, code: int, keep_path: bool}  $values
     * @return array<string, mixed> the values before, for an undo
     *
     * @throws AgentException
     */
    public function updateRedirect(Site $site, SiteRedirect $redirect, array $values): array
    {
        $before = $redirect->only(['target', 'code', 'keep_path']);
        $this->change($site, fn () => $redirect->update($values));

        return $before;
    }

    /**
     * Removes a subdomain, an alias or a redirect from the site and the
     * server; its files and its zone stay. Returns an undo token.
     *
     * @param  'subdomain'|'alias'|'redirect'  $kind
     *
     * @throws AgentException
     */
    public function remove(Site $site, string $kind, string|int $key): ?string
    {
        $saved = ['site_id' => $site->id, 'kind' => $kind];
        $hosts = [];
        $this->change($site, function () use ($site, $kind, $key, &$saved, &$hosts) {
            switch ($kind) {
                case 'subdomain':
                    $sub = $site->subdomains()->findOrFail($key);
                    $saved['row'] = $sub->only(['name', 'web_root']);
                    $hosts = [$sub->name];
                    $sub->delete();
                    break;
                case 'alias':
                    abort_unless(in_array($key, $site->aliases ?? [], true), 404);
                    $saved['row'] = ['name' => $key];
                    $hosts = [$key];
                    $site->update(['aliases' => array_values(array_diff($site->aliases ?? [], [$key]))]);
                    break;
                default:
                    $redirect = $site->redirects()->findOrFail($key);
                    $saved['row'] = $redirect->only(['kind', 'source', 'with_www', 'target', 'code', 'keep_path']);
                    $hosts = $redirect->hosts();
                    $redirect->delete();
            }
        });
        $saved['dns'] = array_values(array_filter(array_map(fn ($h) => $this->zones->removeHost($h), $hosts)));

        $token = Str::random(24);
        Cache::put('site-domain-undo:'.$token, $saved, self::UNDO_SECONDS);

        return $token;
    }

    /**
     * Puts back what remove() took away, unless the name was taken since.
     * Returns a message for the person, or null when the token expired.
     *
     * @throws AgentException
     */
    public function undo(Site $site, string $token): ?string
    {
        $saved = Cache::get('site-domain-undo:'.$token);
        if ($saved === null || $saved['site_id'] !== $site->id) {
            return null;
        }
        $row = $saved['row'];
        $name = $row['name'] ?? $row['source'];
        if (($saved['kind'] !== 'redirect' || $row['kind'] === SiteRedirect::DOMAIN) && ($taken = $this->taken($name))) {
            Cache::forget('site-domain-undo:'.$token);

            return __('Geri alınamadı: :taken', ['taken' => $taken]);
        }
        $this->change($site, fn () => match ($saved['kind']) {
            'subdomain' => $site->subdomains()->create($row),
            'alias' => $site->update(['aliases' => [...($site->aliases ?? []), $row['name']]]),
            default => $site->redirects()->create($row),
        });
        Cache::forget('site-domain-undo:'.$token);
        foreach ($saved['dns'] as $dnsToken) {
            $this->zones->undo($dnsToken);
        }

        return __('Geri alındı.');
    }

    /**
     * After new names went live: their DNS (when the zone is here) and
     * the site's certificate.
     *
     * @param  list<string>  $names
     */
    public function published(Site $site, array $names, bool $createZone = false): void
    {
        foreach ($names as $name) {
            $this->zones->addHost($name, $site, $createZone && ! str_starts_with($name, 'www.'));
        }
        $this->extendCertificate($site->fresh());
    }

    /**
     * Asks Let's Encrypt again when the site's certificate (or its pending
     * request) does not cover every name. Names whose DNS does not point
     * here yet are left out by IssueCertificate and picked up by the
     * hourly servik:ssl-renew. Uploaded certificates are left alone.
     */
    public function extendCertificate(Site $site): bool
    {
        $certificate = $site->certificate;
        if ($certificate !== null && ! $certificate->isLetsEncrypt()) {
            return false;
        }
        if ($certificate === null && ! Setting::get('ssl.auto', true) && $site->certificates()->doesntExist()) {
            return false;
        }
        if ($certificate?->status === CertificateStatus::Issuing || $site->pendingCertificate()?->status === CertificateStatus::Issuing) {
            return false;
        }
        // Reissuing for names that do not resolve here would only get the
        // same certificate again (and count against Let's Encrypt's limits).
        if ($certificate !== null && array_filter($this->missing($site, $certificate), fn ($n) => $this->pointing($n)['ok']) === []) {
            return false;
        }
        if ($certificate?->isWildcard()) {
            // A wildcard only grows by DNS validation of the same domain.
            return false;
        }
        $this->certificates->letsEncrypt($site, auto: true);

        return true;
    }

    /**
     * Names the site answers to that $certificate does not cover.
     *
     * @return list<string>
     */
    public function missing(Site $site, Certificate $certificate): array
    {
        return array_values(array_filter($site->hostnames(), fn ($n) => ! self::covers($certificate->domains ?? [], $n)));
    }

    /**
     * True when a certificate for $names serves $host.
     *
     * @param  list<string>  $names
     */
    public static function covers(array $names, string $host): bool
    {
        foreach ($names as $n) {
            if ($n === $host || (str_starts_with($n, '*.') && Str::after($host, '.') === substr($n, 2) && substr_count($host, '.') === substr_count($n, '.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where a name stands: served over HTTPS, waiting for its certificate
     * or for its DNS to point here.
     *
     * @return array{badge: Badge, hint: string}
     */
    public function state(Site $site, string $name): array
    {
        $certificate = $site->certificate;
        if ($certificate?->isInstalled() && self::covers($certificate->domains ?? [], $name)) {
            return ['badge' => new Badge('HTTPS', 'ok'), 'hint' => 'Sertifikada; '.$certificate->issuerLabel()];
        }
        $pointing = $this->pointing($name);
        if (! $pointing['ok']) {
            $here = implode(', ', $this->dns->serverAddresses()) ?: 'bilinmiyor';
            $there = $pointing['found'] === [] ? __('hiçbir adrese çözülmüyor') : __(':found adresine çözülüyor', ['found' => implode(', ', $pointing['found'])]);

            return ['badge' => new Badge(__('DNS henüz işaret etmiyor'), 'warn'), 'hint' => __('Şu an :there; bu sunucu :here. HTTP ile açılır, sertifika DNS düzelince eklenir.', ['there' => $there, 'here' => $here])];
        }
        if ($certificate?->status === CertificateStatus::Issuing || $site->pendingCertificate()?->status === CertificateStatus::Issuing) {
            return ['badge' => new Badge(__('Sertifika alınıyor'), 'info', busy: true), 'hint' => __('Let\'s Encrypt sertifikası bu adla yenileniyor.')];
        }
        if ($certificate !== null && ! $certificate->isLetsEncrypt()) {
            return ['badge' => new Badge(__('Sertifika kapsamıyor'), 'warn'), 'hint' => __('Yüklenen sertifikada bu ad yok; HTTP ile açılır.')];
        }
        if ($certificate?->isWildcard()) {
            return ['badge' => new Badge(__('Sertifika kapsamıyor'), 'warn'), 'hint' => __('Joker sertifika bu adı kapsamıyor; HTTP ile açılır.')];
        }

        return ['badge' => new Badge(__('Yalnızca HTTP'), 'muted'), 'hint' => __('Sertifikada henüz yok.')];
    }

    /**
     * Public DNS of $name, remembered for five minutes.
     *
     * @return array{ok: bool, found: list<string>}
     */
    private function pointing(string $name): array
    {
        return Cache::remember('servik.dns-points:'.$name, 300, fn () => $this->dns->check($name));
    }

    /**
     * A free folder for a new subdomain: subdomains/<label>.
     */
    public static function defaultRoot(string $label): string
    {
        return Subdomain::FOLDER.'/'.$label;
    }

    /**
     * Why $root cannot be a subdomain's document root, or null.
     */
    public static function rootProblem(string $root): ?string
    {
        if ($root === '' || strlen($root) > 200 || ! preg_match('#^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#', $root)) {
            return __('Site klasörüne göre bir yol yazın, örneğin public_html/blog. Nokta ya da / ile başlayamaz.');
        }
        if ($root === 'logs' || str_starts_with($root, 'logs/')) {
            return __('Günlük klasörü belge kökü olamaz.');
        }

        return null;
    }
}
