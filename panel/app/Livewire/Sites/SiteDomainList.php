<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use App\Services\Dns\Zones;
use App\Services\Sites\SiteDomains;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Alan adları: the names a site answers to besides its domain, in three
 * tabs: subdomains (their own document root), additional domains (the
 * same site) and redirects (a domain or a path sent elsewhere). Every
 * change is tested and written to the server at once and can be undone.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteDomainList extends Component
{
    public const TABS = [
        'alt' => 'Alt alan adları',
        'ek' => 'Ek alan adları',
        'yonlendirmeler' => 'Yönlendirmeler',
    ];

    public int $siteId;

    #[Url(as: 'sekme', except: 'alt')]
    public string $tab = 'alt';

    /** The open form: subdomain, alias or redirect. */
    public ?string $adding = null;

    public string $label = '';

    /** own (subdomains/<label>), inside (public_html/<label>) or custom. */
    public string $rootMode = 'own';

    public string $customRoot = '';

    public string $aliasName = '';

    public bool $createZone = true;

    public ?int $editRedirectId = null;

    public string $redirectKind = SiteRedirect::DOMAIN;

    public string $source = '';

    public bool $withWww = true;

    public string $target = '';

    public int $code = 301;

    public bool $keepPath = true;

    /** @var array{0: string, 1: string|int}|null what the removal dialog asks about */
    public ?array $removing = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'alt';
        }
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'certificate', 'certificates', 'subdomains', 'redirects')->findOrFail($this->siteId);
    }

    public function switchTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'alt';
    }

    public function openSubdomain(SiteDomains $domains): void
    {
        if ($refusal = $domains->limitRefusal($this->site(), 'subdomains')) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->reset('label', 'customRoot');
        $this->rootMode = 'own';
        $this->adding = 'subdomain';
    }

    public function openAlias(SiteDomains $domains): void
    {
        if ($refusal = $domains->limitRefusal($this->site(), 'aliases')) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->reset('aliasName');
        $this->createZone = true;
        $this->adding = 'alias';
    }

    public function openRedirect(SiteDomains $domains, string $kind = SiteRedirect::DOMAIN): void
    {
        $kind = $kind === SiteRedirect::PATH ? SiteRedirect::PATH : SiteRedirect::DOMAIN;
        if ($kind === SiteRedirect::DOMAIN && ($refusal = $domains->limitRefusal($this->site(), 'aliases'))) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetValidation();
        $this->reset('source', 'target', 'editRedirectId');
        $this->redirectKind = $kind;
        $this->withWww = true;
        $this->createZone = true;
        $this->code = 301;
        $this->keepPath = true;
        $this->target = $kind === SiteRedirect::DOMAIN ? 'https://'.$this->site()->domain : '';
        $this->adding = 'redirect';
    }

    public function openEditRedirect(int $id): void
    {
        $redirect = $this->site()->redirects->find($id) ?? abort(404);
        $this->resetValidation();
        $this->editRedirectId = $redirect->id;
        $this->redirectKind = $redirect->kind;
        $this->source = $redirect->source;
        $this->withWww = $redirect->with_www;
        $this->target = $redirect->target;
        $this->code = $redirect->code;
        $this->keepPath = $redirect->keep_path;
        $this->adding = 'redirect';
    }

    public function closeForm(): void
    {
        $this->adding = null;
    }

    /**
     * The subdomain's full name from what was typed: "blog" or
     * "blog.example.com".
     */
    private function subdomainName(Site $site): string
    {
        $label = Hostnames::normalize($this->label);
        if (str_ends_with($label, '.'.$site->domain)) {
            $label = substr($label, 0, -strlen('.'.$site->domain));
        }

        return $label === '' ? '' : $label.'.'.$site->domain;
    }

    private function webRoot(): string
    {
        $label = Hostnames::normalize($this->label);
        $label = $label === '' ? 'blog' : $label;

        return match ($this->rootMode) {
            'inside' => Site::PUBLIC_HTML.'/'.$label,
            'custom' => trim($this->customRoot, " /\t"),
            default => SiteDomains::defaultRoot($label),
        };
    }

    public function saveSubdomain(SiteDomains $domains): void
    {
        $site = $this->site();
        $this->resetValidation();
        $name = $this->subdomainName($site);
        $label = Hostnames::normalize($this->label);
        if ($label === '' || str_contains($label, '/') || ! Hostnames::isValidDomain($name)) {
            $this->addError('label', 'Harf, rakam ve tire kullanın; örneğin blog ya da api.v2.');

            return;
        }
        if ($problem = $domains->nameRefusal($site, $name, auth()->user())) {
            $this->addError('label', $problem);

            return;
        }
        $root = $this->webRoot();
        if ($problem = SiteDomains::rootProblem($root)) {
            $this->addError('customRoot', $problem);

            return;
        }
        if ($refusal = $domains->limitRefusal($site, 'subdomains')) {
            $this->addError('label', $refusal);

            return;
        }
        try {
            $subdomain = $domains->addSubdomain($site, $name, $root);
        } catch (AgentException $e) {
            $this->addError('label', 'Sunucu alt alan adını ekleyemedi: '.$e->forPeople());

            return;
        }
        Quotas::forget();
        $this->adding = null;
        $this->dispatch('toast', message: "{$name} eklendi.", source: $this->getId(), undo: ['method' => 'undoAdd', 'args' => ['subdomain', $subdomain->id]]);
    }

    public function saveAlias(SiteDomains $domains): void
    {
        $site = $this->site();
        $this->resetValidation();
        $name = Hostnames::normalize($this->aliasName);
        if ($problem = $domains->nameRefusal($site, $name, auth()->user())) {
            $this->addError('aliasName', $problem);

            return;
        }
        if ($refusal = $domains->limitRefusal($site, 'aliases')) {
            $this->addError('aliasName', $refusal);

            return;
        }
        try {
            $domains->addAlias($site, $name, $this->createZone && auth()->user()->allows('dns'));
        } catch (AgentException $e) {
            $this->addError('aliasName', 'Sunucu ek alan adını ekleyemedi: '.$e->forPeople());

            return;
        }
        Quotas::forget();
        $this->adding = null;
        $this->dispatch('toast', message: "{$name} artık {$site->domain} sitesini açıyor.", source: $this->getId(), undo: ['method' => 'undoAdd', 'args' => ['alias', $name]]);
    }

    public function saveRedirect(SiteDomains $domains): void
    {
        $site = $this->site();
        $this->resetValidation();
        $this->target = trim($this->target);
        if (! isset(SiteRedirect::CODES[$this->code])) {
            $this->addError('code', 'Listeden bir tür seçin.');
        }
        $targetProblem = self::targetProblem($this->target, $this->redirectKind, $this->keepPath);
        if ($targetProblem) {
            $this->addError('target', $targetProblem);
        }
        $values = ['target' => $this->target, 'code' => $this->code, 'keep_path' => $this->keepPath];

        if ($this->editRedirectId !== null) {
            $redirect = $site->redirects->find($this->editRedirectId) ?? abort(404);
            if ($this->getErrorBag()->isNotEmpty()) {
                return;
            }
            try {
                $before = $domains->updateRedirect($site, $redirect, $values);
            } catch (AgentException $e) {
                $this->addError('target', 'Sunucu yönlendirmeyi kaydedemedi: '.$e->forPeople());

                return;
            }
            $this->adding = null;
            $this->dispatch('toast', message: 'Yönlendirme kaydedildi.', source: $this->getId(), undo: ['method' => 'undoEditRedirect', 'args' => [$redirect->id, $before]]);

            return;
        }

        if ($this->redirectKind === SiteRedirect::DOMAIN) {
            $source = Hostnames::normalize($this->source);
            if (str_starts_with($source, 'www.')) {
                $source = substr($source, 4);
                $this->withWww = true;
            }
            if ($problem = $domains->nameRefusal($site, $source, auth()->user())) {
                $this->addError('source', $problem);
            } elseif ($this->withWww && ($problem = $domains->nameRefusal($site, 'www.'.$source, auth()->user()))) {
                $this->addError('source', $problem);
            }
            if (! $this->getErrorBag()->has('target') && $this->target !== '' && parse_url($this->target, PHP_URL_HOST) === $source) {
                $this->addError('target', 'Hedef, yönlendirilen alan adının kendisi olamaz.');
            }
        } else {
            $source = '/'.trim(trim($this->source), '/');
            if ($problem = self::pathProblem($source)) {
                $this->addError('source', $problem);
            } elseif ($site->redirects->where('kind', SiteRedirect::PATH)->contains('source', $source)) {
                $this->addError('source', 'Bu yol için zaten bir yönlendirme var.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        if ($this->redirectKind === SiteRedirect::DOMAIN && ($refusal = $domains->limitRefusal($site, 'aliases'))) {
            $this->addError('source', $refusal);

            return;
        }
        try {
            $redirect = $domains->addRedirect($site, [
                'kind' => $this->redirectKind,
                'source' => $source,
                'with_www' => $this->redirectKind === SiteRedirect::DOMAIN && $this->withWww,
                ...$values,
            ], $this->createZone && auth()->user()->allows('dns'));
        } catch (AgentException $e) {
            $this->addError('target', 'Sunucu yönlendirmeyi ekleyemedi: '.$e->forPeople());

            return;
        }
        Quotas::forget();
        $this->adding = null;
        $this->dispatch('toast', message: 'Yönlendirme eklendi.', source: $this->getId(), undo: ['method' => 'undoAdd', 'args' => ['redirect', $redirect->id]]);
    }

    /**
     * Why $target cannot be a redirect's target, or null.
     */
    public static function targetProblem(string $target, string $kind, bool $keepPath): ?string
    {
        if ($target === '') {
            return 'Hedef adresi yazın.';
        }
        if (strlen($target) > 2000 || preg_match('/[\s"\'\\\\$;{}`<>^|]/', $target)) {
            return 'Hedefte boşluk, tırnak ya da $ ; { } gibi karakterler olamaz.';
        }
        $relative = str_starts_with($target, '/') && ! str_starts_with($target, '//');
        if ($relative && $kind === SiteRedirect::DOMAIN) {
            return 'Alan adı yönlendirmesi için tam adres yazın, örneğin https://ornek.com.';
        }
        if (! $relative) {
            $parts = parse_url($target);
            if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['user'])) {
                return 'https:// ile başlayan bir adres ya da /yeni-sayfa gibi bir yol yazın.';
            }
        }
        if ($keepPath && (str_contains($target, '?') || str_contains($target, '#'))) {
            return 'Yolu koruyan yönlendirmenin hedefinde ? ya da # olamaz.';
        }

        return null;
    }

    /**
     * Why $path cannot be redirected, or null.
     */
    public static function pathProblem(string $path): ?string
    {
        if ($path === '/') {
            return 'Sitenin tamamını yönlendirmek için alan adı yönlendirmesi ekleyin.';
        }
        if (strlen($path) > 200 || ! preg_match('#^(/[A-Za-z0-9._~-]+)+$#', $path) || preg_match('#/\.\.?(/|$)#', $path)) {
            return 'Yol /eski-sayfa gibi olmalı; harf, rakam, nokta, tire ve alt çizgi kullanın.';
        }
        if (str_starts_with($path, '/.well-known')) {
            return 'Bu yol sertifika doğrulaması için ayrılmış.';
        }

        return null;
    }

    public function undoAdd(SiteDomains $domains, string $kind, string|int $key): void
    {
        if (! in_array($kind, ['subdomain', 'alias', 'redirect'], true)) {
            return;
        }
        $site = $this->site();
        $exists = match ($kind) {
            'subdomain' => $site->subdomains->contains('id', (int) $key),
            'alias' => in_array($key, $site->aliases ?? [], true),
            default => $site->redirects->contains('id', (int) $key),
        };
        if (! $exists) {
            return;
        }
        try {
            $domains->remove($site, $kind, $kind === 'alias' ? (string) $key : (int) $key);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Geri alınamadı: '.$e->forPeople());

            return;
        }
        Quotas::forget();
        $this->dispatch('toast', message: 'Eklenen ad geri alındı.');
    }

    /**
     * @param  array{target?: mixed, code?: mixed, keep_path?: mixed}  $before
     */
    public function undoEditRedirect(SiteDomains $domains, int $id, array $before): void
    {
        $site = $this->site();
        $redirect = $site->redirects->find($id);
        $values = ['target' => (string) ($before['target'] ?? ''), 'code' => (int) ($before['code'] ?? 0), 'keep_path' => (bool) ($before['keep_path'] ?? false)];
        if ($redirect === null || ! isset(SiteRedirect::CODES[$values['code']]) || self::targetProblem($values['target'], $redirect->kind, $values['keep_path']) !== null) {
            return;
        }
        try {
            $domains->updateRedirect($site, $redirect, $values);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Geri alınamadı: '.$e->forPeople());

            return;
        }
        $this->dispatch('toast', message: 'Yönlendirmedeki değişiklik geri alındı.');
    }

    public function confirmRemove(string $kind, string|int $key): void
    {
        if (in_array($kind, ['subdomain', 'alias', 'redirect'], true)) {
            $this->removing = [$kind, $key];
        }
    }

    public function remove(SiteDomains $domains): void
    {
        if ($this->removing === null) {
            return;
        }
        [$kind, $key] = $this->removing;
        $this->removing = null;
        $site = $this->site();
        $label = match ($kind) {
            'subdomain' => $site->subdomains->find((int) $key)?->name,
            'alias' => in_array($key, $site->aliases ?? [], true) ? $key : null,
            default => $site->redirects->find((int) $key)?->source,
        };
        if ($label === null) {
            return;
        }
        try {
            $token = $domains->remove($site, $kind, $kind === 'alias' ? (string) $key : (int) $key);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Sunucu değişikliği uygulayamadı: '.$e->forPeople());

            return;
        }
        Quotas::forget();
        $this->dispatch('toast', message: match ($kind) {
            'subdomain' => "{$label} kaldırıldı; klasörü ve dosyaları duruyor.",
            'alias' => "{$label} artık bu siteyi açmıyor.",
            default => "{$label} yönlendirmesi kaldırıldı.",
        }, source: $this->getId(), undo: ['method' => 'undoRemove', 'args' => [$token]]);
    }

    public function undoRemove(SiteDomains $domains, string $token): void
    {
        try {
            $message = $domains->undo($this->site(), $token);
        } catch (AgentException $e) {
            $message = 'Geri alınamadı: '.$e->forPeople();
        }
        Quotas::forget();
        if ($message !== null) {
            $this->dispatch('toast', message: $message);
        }
    }

    public function updateCertificate(SiteDomains $domains): void
    {
        $site = $this->site();
        $this->dispatch('toast', message: $domains->extendCertificate($site)
            ? 'Sertifika yeni adlarla yeniden alınıyor.'
            : 'Sertifikaya eklenebilecek ad yok: eksik adların DNS\'i henüz bu sunucuya işaret etmiyor.');
    }

    public function render(SiteDomains $domains, Zones $zones, Quotas $quotas): View
    {
        $site = $this->site();
        $customer = $site->customer();
        $report = $customer ? $quotas->report($customer) : null;
        $state = fn (string $name) => $domains->state($site, $name);
        $aliasZone = null;
        $aliasName = Hostnames::normalize($this->aliasName);
        if ($this->adding === 'alias' && Hostnames::isValidDomain($aliasName)) {
            $aliasZone = $zones->holding($aliasName);
        }
        $redirectZone = null;
        if ($this->adding === 'redirect' && $this->redirectKind === SiteRedirect::DOMAIN && Hostnames::isValidDomain($source = Hostnames::normalize($this->source))) {
            $redirectZone = $zones->holding(str_starts_with($source, 'www.') ? substr($source, 4) : $source);
        }

        return view('livewire.sites.domains', [
            'site' => $site,
            'state' => $state,
            'zones' => $zones,
            'subdomains' => $site->subdomains,
            'redirects' => $site->redirects,
            'subdomainUsage' => $report['subdomains'] ?? null,
            'aliasUsage' => $report['aliases'] ?? null,
            'subdomainRefusal' => $domains->limitRefusal($site, 'subdomains'),
            'aliasRefusal' => $domains->limitRefusal($site, 'aliases'),
            'previewRoot' => $this->webRoot(),
            'aliasZone' => $aliasZone,
            'redirectZone' => $redirectZone,
            'canDns' => auth()->user()->allows('dns'),
            'certificateBehind' => $site->certificate?->isLetsEncrypt() && ! $site->certificate->isWildcard() && $domains->missing($site, $site->certificate) !== [],
        ])->title(self::TABS[$this->tab].' · '.$site->domain.' · Servik');
    }
}
