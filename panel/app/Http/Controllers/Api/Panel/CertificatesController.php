<?php

namespace App\Http\Controllers\Api\Panel;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Site;
use App\Services\Ssl\Certificates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SSL: the sites' certificates, a Let's Encrypt certificate on request,
 * renewal and the HTTP to HTTPS redirect.
 */
class CertificatesController extends ApiController
{
    /**
     * GET /api/v1/certificates: every visible site with its certificate.
     */
    public function index(Request $request): JsonResponse
    {
        $sites = Site::visibleTo($this->user($request))->with('certificate', 'certificates')->orderBy('domain')->get();

        return response()->json(['data' => $sites->map(fn (Site $s) => $this->ssl($s))->values()]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        return response()->json(['data' => $this->ssl($this->findSite($request, $domain, ['certificates']))]);
    }

    /**
     * POST /api/v1/sites/{domain}/ssl {wildcard?, only_domain?}: asks Let's
     * Encrypt for a certificate (a new one for the site's names, or the
     * same names again).
     */
    public function issue(Request $request, Certificates $certificates, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['certificates', 'subdomains', 'redirects']);
        $data = $request->validate(['wildcard' => ['nullable', 'boolean'], 'only_domain' => ['nullable', 'boolean']]);
        if ($refusal = $this->busy($site)) {
            return $this->refuse($refusal, 409);
        }
        $wildcard = ($data['wildcard'] ?? false) && $certificates->zoneFor($site->domain) !== null;
        if (($data['wildcard'] ?? false) && ! $wildcard) {
            return $this->refuse(__('Joker sertifika için alan adının DNS bölgesi bu sunucuda olmalı.'));
        }
        if (($data['only_domain'] ?? false) && ! $wildcard) {
            // Only the domain itself: drop the other names from this request.
            $site->setAttribute('aliases', []);
            $site->setRelation('subdomains', collect());
            $site->setRelation('redirects', collect());
        }
        $certificate = $certificates->letsEncrypt($site, $wildcard);
        $this->audit($request, 'SSL · '.$site->domain, $certificate->isInstalled()
            ? __(':domain sertifikası yeniden alınıyor.', ['domain' => $site->domain])
            : __(':domain için Let\'s Encrypt sertifikası alınıyor.', ['domain' => $site->domain]), route('sites.ssl', $site, false));

        return response()->json(['data' => $this->ssl($site->fresh(['certificate', 'certificates']))], 202);
    }

    /**
     * POST /api/v1/sites/{domain}/ssl/renew: renews a Let's Encrypt
     * certificate now.
     */
    public function renew(Request $request, Certificates $certificates, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['certificates']);
        $certificate = $site->certificate;
        if ($certificate === null || ! $certificate->isLetsEncrypt()) {
            return $this->refuse(__('Bu sitenin Let\'s Encrypt sertifikası yok; önce sertifika alın.'));
        }
        if ($refusal = $this->busy($site)) {
            return $this->refuse($refusal, 409);
        }
        $certificates->reissue($certificate);
        $this->audit($request, 'SSL · '.$site->domain, __('Sertifika yenileniyor.'), route('sites.ssl', $site, false));

        return response()->json(['data' => $this->ssl($site->fresh(['certificate', 'certificates']))], 202);
    }

    /**
     * PUT /api/v1/sites/{domain}/ssl/https {redirect}: sends HTTP to HTTPS,
     * or stops doing so.
     */
    public function https(Request $request, Certificates $certificates, string $domain): JsonResponse
    {
        $site = $this->findSite($request, $domain, ['certificates']);
        $on = (bool) $request->validate(['redirect' => ['required', 'boolean']])['redirect'];
        if ($site->status->isBusy()) {
            return $this->refuse(__('Site şu an değişiyor; biraz sonra yeniden deneyin.'), 409);
        }
        if ($on && ! $site->hasHttps()) {
            return $this->refuse(__('Önce bir sertifika gerekiyor; HTTPS olmadan yönlendirme açılamaz.'));
        }
        if ((bool) $site->https_redirect !== $on) {
            $certificates->setRedirect($site, $on);
            $this->audit($request, 'SSL · '.$site->domain, $on ? __('HTTP istekleri HTTPS\'e yönlendirilecek.') : __('HTTP yönlendirmesi kapatıldı.'), route('sites.ssl', $site, false));
        }

        return response()->json(['data' => $this->ssl($site->fresh(['certificate', 'certificates']))]);
    }

    private function busy(Site $site): ?string
    {
        $pending = $site->pendingCertificate();
        if ($site->status->isBusy() || $site->certificate?->status === CertificateStatus::Issuing || $pending?->status === CertificateStatus::Issuing) {
            return __('Bu site için şu an bir sertifika alınıyor ya da site değişiyor.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ssl(Site $site): array
    {
        $certificate = $site->certificate;
        $pending = $site->pendingCertificate();
        $state = $site->sslState();

        return [
            'site' => $site->domain,
            'state' => ['text' => $state->label(), 'tone' => $state->tone(), 'busy' => $state->isBusy()],
            'https_redirect' => (bool) $site->https_redirect,
            'certificate' => $certificate ? $this->certificate($certificate) : null,
            'pending' => $pending && $pending->id !== $certificate?->id ? $this->certificate($pending) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function certificate(Certificate $c): array
    {
        return [
            'source' => $c->source,
            'issuer' => $c->issuerLabel(),
            'names' => $c->domains ?? [],
            'status' => $c->status->value,
            'not_after' => $c->not_after?->toIso8601String(),
            'days_left' => $c->daysLeft(),
            'auto_renew' => (bool) $c->auto_renew,
            'error' => $c->last_error ?? $c->renew_error,
        ];
    }
}
