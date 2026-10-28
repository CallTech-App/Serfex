<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteCache;
use App\Services\Sites\SiteDomains;
use App\Support\Num;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Önbellek ve hız: the site's page cache, browser cache and compression
 * (SiteCache), tried on the web server before they are kept, and a button
 * that empties the page cache.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteCaching extends Component
{
    public int $siteId;

    public bool $page = false;

    public int $pageMinutes = SiteCache::DEFAULT_MINUTES;

    public string $exclude = '';

    public bool $browser = false;

    public int $browserDays = SiteCache::DEFAULT_DAYS;

    public bool $gzip = false;

    public function mount(int $site): void
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $site->id;
        $this->useSettings(SiteCache::settings($site));
    }

    /**
     * @param  array{page: bool, page_minutes: int, exclude: list<string>, browser: bool, browser_days: int, gzip: bool}  $s
     */
    private function useSettings(array $s): void
    {
        $this->page = $s['page'];
        $this->pageMinutes = $s['page_minutes'];
        $this->exclude = implode("\n", $s['exclude']);
        $this->browser = $s['browser'];
        $this->browserDays = $s['browser_days'];
        $this->gzip = $s['gzip'];
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer', 'laravelApp.processes')->findOrFail($this->siteId);
    }

    public function save(SiteDomains $domains): void
    {
        $this->resetValidation();
        $site = $this->site();
        $exclude = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $this->exclude) ?: []))));
        if (count($exclude) > SiteCache::MAX_EXCLUDE) {
            $this->addError('exclude', __('En fazla :count yol yazılabilir.', ['count' => SiteCache::MAX_EXCLUDE]));
        }
        foreach ($exclude as $path) {
            if (! preg_match(SiteCache::EXCLUDE_PATTERN, $path) || str_contains($path, '..')) {
                $this->addError('exclude', __(':path geçerli bir yol değil; / ile başlamalı, boşluk ve tırnak içermemeli.', ['path' => Str::limit($path, 60)]));
                break;
            }
        }
        if (! array_key_exists($this->pageMinutes, SiteCache::PAGE_MINUTES)) {
            $this->addError('pageMinutes', __('Listeden bir süre seçin.'));
        }
        if (! array_key_exists($this->browserDays, SiteCache::BROWSER_DAYS)) {
            $this->addError('browserDays', __('Listeden bir süre seçin.'));
        }
        if ($this->page && ($blocked = SiteCache::pageCacheBlocked($site)) !== null) {
            $this->addError('page', $blocked);
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $cache = [
            'page' => $this->page,
            'page_minutes' => $this->pageMinutes,
            'exclude' => $exclude,
            'browser' => $this->browser,
            'browser_days' => $this->browserDays,
            'gzip' => $this->gzip,
        ];
        $before = $site->cache;
        if (SiteCache::settings($site) === $cache) {
            $this->dispatch('toast', message: __('Değişiklik yok.'));

            return;
        }
        try {
            $domains->change($site, fn () => $site->update(['cache' => $cache]));
        } catch (AgentException $e) {
            $this->addError('page', $e->forPeople());

            return;
        }
        $this->exclude = implode("\n", $exclude);
        $token = Str::random(24);
        Cache::put('site-cache-undo:'.$token, ['site_id' => $site->id, 'cache' => $before], SiteDomains::UNDO_SECONDS);
        $this->dispatch('toast', message: __('Önbellek ayarları uygulandı.'), source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function undo(SiteDomains $domains, string $token): void
    {
        $saved = Cache::pull('site-cache-undo:'.$token);
        $site = $this->site();
        if ($saved === null || $saved['site_id'] !== $site->id) {
            return;
        }
        try {
            $domains->change($site, fn () => $site->update(['cache' => $saved['cache']]));
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Geri alınamadı: :for_people', ['for_people' => $e->forPeople()]));

            return;
        }
        $this->useSettings(SiteCache::settings($site));
        $this->resetValidation();
        $this->dispatch('toast', message: __('Önbellek ayarları önceki hâline döndü.'));
    }

    public function purge(SiteCache $cache): void
    {
        try {
            $files = $cache->purge($this->site());
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Önbellek temizlenemedi: :for_people', ['for_people' => $e->forPeople()]));

            return;
        }
        $this->dispatch('toast', message: $files > 0
            ? __('Önbellek temizlendi: :count sayfa silindi.', ['count' => Num::format($files, 0)])
            : __('Önbellek zaten boştu.'));
    }

    public function render(SiteCache $cache): View
    {
        $site = $this->site();
        $saved = SiteCache::settings($site);

        return view('livewire.sites.cache', [
            'site' => $site,
            'saved' => $saved,
            'usage' => $saved['page'] ? $cache->usage($site) : null,
            'blocked' => SiteCache::pageCacheBlocked($site),
        ])->title(__('Önbellek ve hız · :domain · Serfex', ['domain' => $site->domain]));
    }
}
