<?php

namespace App\Livewire;

use App\Livewire\Attributes\ForEveryAccount;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\Subdomain;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Ctrl+K: one box that finds sites (by any name they answer to), DNS
 * zones, customers, mailboxes, databases, Laravel applications and panel
 * pages. Every account sees only what it may open.
 */
#[ForEveryAccount]
class QuickSearch extends Component
{
    /** Results per group. */
    public const PER_GROUP = 5;

    public string $q = '';

    /**
     * Panel pages: [label, route, route parameters, icon, extra words, who].
     * who: admin (any administrator), full (full administrators), write
     * (administrators who may change things), a package feature for
     * customers too, or "all" for every hosting account.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: string, 5: string}>
     */
    private static function pages(): array
    {
        return [
            [__('Genel Bakış'), 'dashboard', [], 'layout-dashboard', __('pano özet anasayfa'), 'all'],
            [__('Canlı İzleme'), 'monitor.index', [], 'activity', __('izleme saldırı kaynak cpu bellek posta kuyruğu'), 'admin'],
            ['Servik AI', 'ai.index', [], 'sparkles', 'yapay zeka asistan claude', 'write'],
            [__('Siteler'), 'sites.index', [], 'globe', __('site ekle web'), 'all'],
            ['Laravel', 'laravel.index', [], 'layers', __('uygulama dağıtım deploy kuyruk'), 'laravel'],
            [__('Alan Adları ve DNS'), 'dns.index', [], 'network', __('dns bölge kayıt zone'), 'dns'],
            [__('DNS ayarları'), 'dns.settings', [], 'network', __('ad sunucusu nameserver şablon'), 'admin'],
            [__('E-posta'), 'mail.index', [], 'mail', __('posta kutusu mailbox webmail'), 'mail'],
            [__('E-posta ayarları'), 'mail.settings', [], 'mail', __('posta sunucusu dkim spam'), 'admin'],
            [__('Veritabanları'), 'databases.index', [], 'database', 'mysql postgresql mariadb phpmyadmin', 'all'],
            [__('Veritabanı sunucuları'), 'databases.settings', [], 'database', __('mysql mariadb postgresql kurulum'), 'admin'],
            [__('SSL Sertifikaları'), 'ssl.index', [], 'shield-check', __('https lets encrypt sertifika'), 'all'],
            [__('SSL ayarları'), 'ssl.settings', [], 'shield-check', __('otomatik ssl yenileme'), 'admin'],
            [__('Müşteriler ve Paketler'), 'customers.index', [], 'users', __('müşteri paket kota'), 'admin'],
            [__('Güvenlik'), 'security.index', [], 'shield-alert', __('güvenlik duvarı waf tarama fail2ban ssh 2fa'), 'admin'],
            [__('Yedekler'), 'backups.index', [], 'archive', __('yedek geri yükleme backup s3 sftp'), 'backups'],
            [__('Hizmetler'), 'services.index', [], 'server-cog', __('servis nginx mysql postfix yeniden başlat'), 'admin'],
            [__('PHP sürümleri'), 'services.index', ['sekme' => 'php'], 'code', __('php ini eklenti'), 'admin'],
            [__('Yazılım Yönetimi'), 'components.index', [], 'package-plus', __('bileşen apache redis node composer kurulum'), 'admin'],
            [__('Araçlar ve Ayarlar'), 'settings.general', [], 'settings', __('genel ayarlar panel adresi port saat dilimi'), 'full'],
            [__('Sunucu ayarları'), 'settings.server', [], 'settings', __('sunucu adı hostname ip'), 'full'],
            [__('Bildirimler'), 'settings.notifications', [], 'bell', __('e-posta webhook uyarı'), 'full'],
            [__('Yöneticiler'), 'settings.admins', [], 'users', __('yönetici rol davet'), 'full'],
            [__('API anahtarları'), 'settings.api', [], 'key-round', __('api token anahtar'), 'full'],
            [__('İşlem günlüğü'), 'settings.audit', [], 'file-text', __('kayıt denetim log geçmiş'), 'full'],
            [__('Güncellemeler'), 'settings.updates', [], 'refresh-cw', __('sürüm güncelle yükselt'), 'full'],
            [__('Servik AI ayarları'), 'ai.settings', [], 'sparkles', __('api anahtarı model bütçe'), 'full'],
            [__('Hesap güvenliği'), 'account.security', [], 'lock', __('parola iki adımlı 2fa oturum'), 'everyone'],
        ];
    }

    /**
     * Lowercase ASCII, so "islem" finds "İşlem günlüğü".
     */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower(str_replace([__('İ'), 'I'], ['i', __('ı')], $text)), ['ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']);
    }

    private static function mayOpen(User $user, string $who): bool
    {
        return match ($who) {
            'everyone' => true,
            'all' => $user->is_admin || $user->isCustomer(),
            'admin' => $user->is_admin,
            'write' => $user->is_admin && ! $user->isReadOnly(),
            'full' => $user->is_admin && $user->isFullAdmin(),
            'laravel' => $user->is_admin || ($user->isCustomer() && $user->allows('laravel')) || $user->laravelMemberships()->exists(),
            default => $user->is_admin || ($user->isCustomer() && $user->allows($who)),
        };
    }

    /**
     * @return list<array{group: string, items: list<array{label: string, detail: ?string, url: string, icon: string}>}>
     */
    public function results(): array
    {
        $user = auth()->user();
        $q = trim($this->q);
        if ($user === null || $q === '') {
            return [];
        }
        $groups = [];
        $add = function (string $group, iterable $items) use (&$groups) {
            $list = [];
            foreach ($items as $item) {
                $list[] = $item;
            }
            if ($list !== []) {
                $groups[] = ['group' => $group, 'items' => array_slice($list, 0, self::PER_GROUP)];
            }
        };

        $folded = self::fold($q);
        $add(__('Sayfalar'), collect(self::pages())
            ->filter(fn ($p) => self::mayOpen($user, $p[5]) && str_contains(self::fold($p[0].' '.$p[4]), $folded))
            ->map(fn ($p) => ['label' => $p[0], 'detail' => null, 'url' => route($p[1], $p[2]), 'icon' => $p[3]]));

        if (mb_strlen($q) < 2) {
            return $groups;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], mb_strtolower($q)).'%';
        $hosting = $user->is_admin || $user->isCustomer();

        if ($hosting) {
            $sites = Site::visibleTo($user)
                ->where(fn (Builder $w) => $w->where('domain', 'ilike', $like)->orWhereRaw('aliases::text ilike ?', [$like]))
                ->orderByRaw('length(domain)')->limit(self::PER_GROUP)->get();
            $subdomains = Subdomain::with('site')->whereHas('site', fn ($s) => $s->visibleTo($user))
                ->where('name', 'ilike', $like)->orderBy('name')->limit(self::PER_GROUP)->get();
            $redirects = SiteRedirect::with('site')->whereHas('site', fn ($s) => $s->visibleTo($user))
                ->where('kind', SiteRedirect::DOMAIN)->where('source', 'ilike', $like)->limit(self::PER_GROUP)->get();
            $add(__('Siteler ve alan adları'), [
                ...$sites->map(function (Site $s) use ($q) {
                    $alias = collect($s->aliases ?? [])->first(fn ($a) => str_contains($a, mb_strtolower($q)));
                    $detail = $alias && ! str_contains($s->domain, mb_strtolower($q)) ? __('Ek alan adı: :alias', ['alias' => $alias]) : __('Site · PHP :php_version', ['php_version' => $s->php_version]);

                    return ['label' => $s->domain, 'detail' => $detail, 'url' => route('sites.show', $s), 'icon' => 'globe', 'mono' => true];
                }),
                ...$subdomains->map(fn (Subdomain $s) => ['label' => $s->name, 'detail' => __('Alt alan adı · :domain', ['domain' => $s->site->domain]), 'url' => route('sites.domains', $s->site), 'icon' => 'globe', 'mono' => true]),
                ...$redirects->map(fn (SiteRedirect $r) => ['label' => $r->source, 'detail' => __('Yönlendirme · :domain', ['domain' => $r->site->domain]), 'url' => route('sites.domains', ['site' => $r->site, 'sekme' => 'yonlendirmeler']), 'icon' => 'corner-up-right', 'mono' => true]),
            ]);

            if ($user->allows('dns')) {
                $add(__('DNS bölgeleri'), DnsZone::visibleTo($user)->where('name', 'ilike', $like)->orderByRaw('length(name)')->limit(self::PER_GROUP)->get()
                    ->map(fn (DnsZone $z) => ['label' => $z->name, 'detail' => __('DNS bölgesi'), 'url' => route('dns.show', $z), 'icon' => 'network', 'mono' => true]));
            }
            if ($user->is_admin) {
                $add(__('Müşteriler'), Customer::query()
                    ->where(fn (Builder $w) => $w->where('name', 'ilike', $like)->orWhere('company', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('username', 'ilike', $like))
                    ->orderBy('name')->limit(self::PER_GROUP)->get()
                    ->map(fn (Customer $c) => ['label' => $c->displayName(), 'detail' => $c->email, 'url' => route('customers.show', $c), 'icon' => 'user']));
            }
            if ($user->allows('mail')) {
                $domains = MailDomain::visibleTo($user)->select('id');
                $add(__('Posta kutuları'), Mailbox::with('domain')->whereIn('mail_domain_id', $domains)
                    ->whereRaw("(mailboxes.name || '@' || (select name from mail_domains where mail_domains.id = mailboxes.mail_domain_id)) ilike ?", [$like])
                    ->orderBy('name')->limit(self::PER_GROUP)->get()
                    ->map(fn (Mailbox $m) => ['label' => $m->address(), 'detail' => __('Posta kutusu'), 'url' => route('mail.show', $m->domain), 'icon' => 'mail', 'mono' => true]));
            }
            $add(__('Veritabanları'), Database::visibleTo($user)->with('site')->where('name', 'ilike', $like)->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Database $d) => ['label' => $d->name, 'detail' => __('Veritabanı').($d->site ? ' · '.$d->site->domain : ''), 'url' => route('databases.show', $d), 'icon' => 'database', 'mono' => true]));
        }

        if (self::mayOpen($user, 'laravel')) {
            $add(__('Laravel uygulamaları'), LaravelApp::with('site')->whereNull('preview_of_id')
                ->when(! $user->is_admin, fn ($w) => $w->where(fn ($w) => $w
                    ->whereIn('id', $user->laravelMemberships()->select('laravel_app_id'))
                    ->when($user->isCustomer(), fn ($w) => $w->orWhereHas('site', fn ($s) => $s->visibleTo($user)))))
                ->whereHas('site', fn ($s) => $s->where('domain', 'ilike', $like))
                ->limit(self::PER_GROUP)->get()
                ->map(fn (LaravelApp $a) => ['label' => $a->site->domain, 'detail' => __('Laravel uygulaması'), 'url' => route('laravel.show', $a), 'icon' => 'layers', 'mono' => true]));
        }

        return $groups;
    }

    public function render(): View
    {
        return view('livewire.quick-search', ['groups' => $this->results()]);
    }
}
