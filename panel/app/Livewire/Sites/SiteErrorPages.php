<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteDomains;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hata sayfaları: the pages visitors see when the web server itself
 * answers 403, 404 or a server error (PHP not answering, for example),
 * instead of Nginx's plain ones. Pages the site's own code produces
 * (Laravel, WordPress) are left to it.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteErrorPages extends Component
{
    /** key => [codes shown, name, when it shows, title, text, English]. */
    public const PAGES = [
        '403' => ['403', 'Erişim engellendi', 'Klasör listeleme kapalıyken, engellenen yollarda ve parola korumasından geçemeyenlere',
            'Bu sayfaya erişim izniniz yok', 'Aradığınız sayfa korunuyor ya da erişime kapalı.', 'You do not have permission to view this page.'],
        '404' => ['404', 'Sayfa bulunamadı', 'Var olmayan bir dosya istendiğinde (sitenin kendi kodu 404 vermediyse)',
            'Sayfa bulunamadı', 'Aradığınız sayfa taşınmış, silinmiş ya da hiç var olmamış olabilir.', 'The page you are looking for could not be found.'],
        '50x' => ['500 · 502 · 504', 'Sunucu hatası', 'PHP ya da uygulama yanıt vermediğinde veya zaman aşımında',
            'Geçici bir sorun var', 'Site şu anda yanıt veremiyor. Lütfen birkaç dakika sonra yeniden deneyin.', 'Something went wrong on our side. Please try again in a few minutes.'],
    ];

    public const MAX_BYTES = 256 * 1024;

    public int $siteId;

    public ?string $editing = null;

    public string $html = '';

    public ?string $resetting = null;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer')->findOrFail($this->siteId);
    }

    /**
     * Servik's starting page for $key, in the site's name and the
     * panel user's language.
     */
    public static function template(string $key, string $domain): string
    {
        [$code, , , $title, $text, $english] = self::PAGES[$key];

        return view('sites.error-page', [
            'code' => $key === '50x' ? '500' : $code,
            'title' => __($title),
            'text' => __($text),
            // A Turkish page carries an English line for foreign visitors.
            'english' => app()->getLocale() === 'tr' ? $english : null,
            'domain' => $domain,
        ])->render();
    }

    public function openEdit(string $key): void
    {
        if (! isset(self::PAGES[$key])) {
            return;
        }
        $site = $this->site();
        $this->resetValidation();
        $this->html = $site->error_pages[$key] ?? self::template($key, $site->domain);
        $this->editing = $key;
    }

    public function closeEdit(): void
    {
        $this->editing = null;
    }

    public function save(SiteDomains $domains): void
    {
        $this->resetValidation();
        $key = $this->editing;
        if ($key === null || ! isset(self::PAGES[$key])) {
            return;
        }
        if (trim($this->html) === '') {
            $this->addError('html', __('Sayfanın HTML\'ini yazın; web sunucusunun kendi sayfasına dönmek için "Varsayılana dön"ü kullanın.'));

            return;
        }
        if (strlen($this->html) > self::MAX_BYTES) {
            $this->addError('html', __('Sayfa en fazla 256 KB olabilir.'));

            return;
        }
        $site = $this->site();
        $token = $this->remember($site, $key);
        try {
            $domains->change($site, fn () => $site->update(['error_pages' => [...($site->error_pages ?? []), $key => $this->html]]));
        } catch (AgentException $e) {
            $this->addError('html', __('Sunucu sayfayı kaydedemedi: :for_people', ['for_people' => $e->forPeople()]));

            return;
        }
        $this->editing = null;
        $this->dispatch('toast', message: __(':pages sayfası kaydedildi.', ['pages' => self::PAGES[$key][1]]), source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function confirmReset(string $key): void
    {
        $this->resetting = isset(self::PAGES[$key]) ? $key : null;
    }

    public function restoreDefault(SiteDomains $domains): void
    {
        $key = $this->resetting;
        $this->resetting = null;
        $site = $this->site();
        if ($key === null || ! isset($site->error_pages[$key])) {
            return;
        }
        $token = $this->remember($site, $key);
        $pages = $site->error_pages;
        unset($pages[$key]);
        try {
            $domains->change($site, fn () => $site->update(['error_pages' => $pages ?: null]));
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Sunucu değişikliği uygulayamadı: :for_people', ['for_people' => $e->forPeople()]));

            return;
        }
        $this->dispatch('toast', message: __(':pages için web sunucusunun sayfası kullanılıyor.', ['pages' => self::PAGES[$key][1]]), source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function undo(SiteDomains $domains, string $token): void
    {
        $saved = Cache::pull('site-error-undo:'.$token);
        $site = $this->site();
        if ($saved === null || $saved['site_id'] !== $site->id) {
            return;
        }
        $pages = $site->error_pages ?? [];
        if ($saved['html'] === null) {
            unset($pages[$saved['key']]);
        } else {
            $pages[$saved['key']] = $saved['html'];
        }
        try {
            $domains->change($site, fn () => $site->update(['error_pages' => $pages ?: null]));
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Geri alınamadı: :for_people', ['for_people' => $e->forPeople()]));

            return;
        }
        $this->dispatch('toast', message: __('Hata sayfası geri alındı.'));
    }

    /**
     * Keeps a page as it is now for an undo.
     */
    private function remember(Site $site, string $key): string
    {
        $token = Str::random(24);
        Cache::put('site-error-undo:'.$token, ['site_id' => $site->id, 'key' => $key, 'html' => $site->error_pages[$key] ?? null], SiteDomains::UNDO_SECONDS);

        return $token;
    }

    public function render(): View
    {
        $site = $this->site();

        return view('livewire.sites.error-pages', [
            'site' => $site,
            'pages' => $site->error_pages ?? [],
        ])->title(__('Hata sayfaları · :domain · Servik', ['domain' => $site->domain]));
    }
}
