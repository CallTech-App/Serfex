<?php

namespace App\Services\System;

use App\Models\Database;
use App\Models\DnsZone;
use App\Models\Mailbox;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Databases\DatabaseServers;
use App\Support\Badge;
use Illuminate\Support\Facades\Cache;

/**
 * The server's system services (Hizmetler): what runs, since when and
 * with what, start, stop, restart and reload through the agent, recent log
 * lines and the few settings worth changing. Stopped or failed services
 * are kept in the cache for the dashboard and the menu, refreshed every
 * minute by serfex:services-check and whenever the page reads them.
 */
class SystemServices
{
    public const PROBLEMS_KEY = 'services.problems';

    /** Groups in the order the page shows them. */
    public const GROUPS = [
        'hosting' => 'Barındırma hizmetleri',
        'system' => 'Sistem',
        'serfex' => 'Serfex\'in kendi hizmetleri',
    ];

    /**
     * Settings the page offers: section => [key => [label, unit, min, max,
     * help, kind]]. kind is "number" when left out, "switch" for on/off,
     * "link" for a value shown here but changed on another page.
     */
    public const SETTINGS = [
        'nginx' => [
            'worker_processes' => ['İşçi süreç sayısı', '', null, null, '"auto" her işlemci çekirdeğine bir işçi verir.'],
            'worker_connections' => ['İşçi başına bağlantı', '', 128, 65535, 'Aynı anda açık bağlantı sayısı = işçi × bu değer.'],
            'client_max_body_mb' => ['En büyük istek gövdesi', 'MB', 1, 102400, 'Yükleme sınırı. PHP\'nin post_max_size değerini daha büyük tutan siteler kendi sınırını alır.'],
            'keepalive_timeout' => ['Açık bağlantı süresi', 'saniye', 0, 300, 'Boşta bekleyen tarayıcı bağlantısı bu süre sonunda kapanır.'],
        ],
        'database' => [
            'max_connections' => ['En fazla bağlantı', '', 10, 10000, 'Tüm veritabanları için toplam.'],
            'innodb_buffer_pool_mb' => ['InnoDB önbelleği', 'MB', 32, 1048576, 'Sık okunan veriler bellekte tutulur; sunucu belleğinin en fazla yarısı önerilir.'],
            'max_allowed_packet_mb' => ['En büyük sorgu paketi', 'MB', 1, 1024, 'Büyük içe aktarmalar ve BLOB alanları için.'],
        ],
        'postfix' => [
            'message_size_mb' => ['En büyük ileti', 'MB', 1, 2048, 'Ekler dahil; base64 yüzünden dosyalar yaklaşık %35 büyür.'],
        ],
        'apache' => [
            'max_request_workers' => ['Eşzamanlı istek sınırı', '', 25, 2000, 'Apache\'nin aynı anda işlediği istek sayısı; fazlası sırada bekler. Apache\'yi kullanan tüm siteler için toplam.'],
            'timeout' => ['İstek zaman aşımı', 'saniye', 10, 300, 'Yavaş bir istek en çok bu kadar beklenir. Nginx de Apache\'yi en çok 300 saniye bekler.'],
        ],
        'powerdns' => [
            'cache_ttl' => ['Yanıt önbelleği', 'saniye', 0, 86400, 'Aynı sorunun yanıtı bu süre bellekte tutulur; 0 önbelleği kapatır. Panelden yapılan kayıt değişiklikleri önbelleği hemen temizler.'],
            'negquery_cache_ttl' => ['Olumsuz yanıt önbelleği', 'saniye', 0, 86400, '"Böyle bir kayıt yok" yanıtları bu süre bellekte tutulur.'],
            'log_queries' => ['Sorgu kaydı', '', null, null, 'Açıkken her DNS sorgusu bu hizmetin günlüğüne yazılır. Günlüğü hızla büyütür; sorun ararken açın.', 'switch'],
            'axfr_allow' => ['İkincil ad sunucuları', '', null, null, 'Bölgelerinizi kopyalayıp yayınlayan sunucular. Sunucu DNS ayarları sayfasında değiştirilir; orada bölgeler de buna göre ayarlanır.', 'link'],
        ],
    ];

    /** @var list<array<string, mixed>>|null */
    private ?array $list = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly DatabaseServers $databases,
    ) {}

    /**
     * Every installed service the agent knows; null when the agent cannot
     * be reached (with $error set when it answered with an error).
     *
     * @return list<array<string, mixed>>|null
     */
    public function list(?string &$error = null): ?array
    {
        if ($this->list !== null) {
            return $this->list;
        }
        try {
            $list = (array) ($this->agent->call('service.list', [], 30)['services'] ?? []);
        } catch (AgentException $e) {
            $error = $e instanceof AgentUnavailableException ? null : $e->reason;

            return null;
        }
        $this->list = array_values(array_map(fn ($s) => ($s['protected'] ?? '') === '' ? $s : ['protected' => self::protectedReason($s['unit'])] + $s, $list));
        $this->remember($this->list);

        return $this->list;
    }

    /**
     * Why a service cannot be stopped, as the page says it.
     */
    public static function protectedReason(string $unit): string
    {
        return match ($unit) {
            'ssh' => __('Sunucuya bağlanmanın yolu; durdurulursa sunucuya SSH ile giremezsiniz.'),
            'serfex-agent' => __('Panel sunucuyu onunla yönetir; durdurulamaz.'),
            default => __('Panel onsuz çalışmaz; durdurulamaz.'),
        };
    }

    /**
     * One service by unit, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $unit, ?string &$error = null): ?array
    {
        foreach ($this->list($error) ?? [] as $s) {
            if ($s['unit'] === $unit) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Services grouped as the page shows them.
     *
     * @param  list<array<string, mixed>>  $list
     * @return array<string, list<array<string, mixed>>>
     */
    public function grouped(array $list): array
    {
        $out = [];
        foreach (array_keys(self::GROUPS) as $group) {
            $rows = array_values(array_filter($list, fn ($s) => ($s['group'] ?? 'system') === $group));
            if ($rows !== []) {
                $out[$group] = $rows;
            }
        }

        return $out;
    }

    /**
     * Stopped or failed services, as last seen.
     *
     * @return list<array{unit: string, name: string, state: string}>
     */
    public static function problems(): array
    {
        return Cache::get(self::PROBLEMS_KEY, []);
    }

    /**
     * True once the services have been read since the cache was emptied.
     */
    public static function checked(): bool
    {
        return Cache::has(self::PROBLEMS_KEY);
    }

    /**
     * Refreshes the problems from the agent.
     */
    public function refresh(): void
    {
        $this->list = null;
        $this->list();
    }

    /**
     * True when a service needs attention: it failed, or it is set to
     * start at boot but is not running.
     *
     * @param  array<string, mixed>  $s
     */
    public static function isProblem(array $s): bool
    {
        return ($s['state'] ?? '') === 'failed'
            || (($s['state'] ?? '') === 'inactive' && ($s['enabled'] ?? '') === 'enabled');
    }

    /**
     * The state as the page shows it.
     *
     * @param  array<string, mixed>  $s
     */
    public static function badge(array $s): Badge
    {
        return match ($s['state'] ?? '') {
            'active', 'reloading' => new Badge(__('Çalışıyor'), 'ok'),
            'activating' => new Badge(__('Başlıyor'), 'info', true),
            'deactivating' => new Badge('Duruyor', 'info', true),
            'failed' => new Badge(__('Hata verdi'), 'crit'),
            default => self::isProblem($s) ? new Badge(__('Durdu'), 'crit') : new Badge(__('Kapalı'), 'muted'),
        };
    }

    /**
     * @param  array<string, mixed>  $s
     */
    public static function running(array $s): bool
    {
        return in_array($s['state'] ?? '', ['active', 'reloading', 'activating'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private function remember(array $list): void
    {
        $problems = array_values(array_map(
            fn ($s) => ['unit' => $s['unit'], 'name' => $s['name'], 'state' => $s['state']],
            array_filter($list, fn ($s) => self::isProblem($s)),
        ));
        Cache::forever(self::PROBLEMS_KEY, $problems);
    }

    /**
     * What a stop or restart of the service interrupts, in a sentence, or
     * null when nothing customers see.
     */
    public function affects(string $unit): ?string
    {
        $sites = fn (int $n) => $n === 1 ? __('1 site') : __(':n site', ['n' => $n]);
        $text = match (true) {
            $unit === 'nginx' => ($n = Site::count()) ? __('Tüm siteler (:n) yanıt vermez.', ['n' => $sites($n)]) : null,
            $unit === 'apache2' => ($n = Site::where('web_server', Site::APACHE)->count()) ? __('Apache arkasındaki :n yanıt vermez.', ['n' => $sites($n)]) : null,
            (bool) preg_match('/^php(\d\.\d)-fpm$/', $unit, $m) => ($n = Site::where('php_version', $m[1])->count()) ? __('PHP :m kullanan :n PHP sayfası açamaz.', ['m' => $m[1], 'n' => $sites($n)]) : null,
            in_array($unit, ['mysql', 'mariadb'], true) => ($n = Database::where('server', DatabaseServers::MYSQL)->count()) ? __(':n veritabanına bağlanılamaz.', ['n' => $n]) : null,
            str_starts_with($unit, 'postgresql@') && str_ends_with($unit, '-main') => ($n = Database::where('server', DatabaseServers::POSTGRESQL)->count()) ? __(':n PostgreSQL veritabanına bağlanılamaz.', ['n' => $n]) : null,
            $unit === 'pdns@serfex' => ($n = DnsZone::count()) ? __(':n alan adının DNS kayıtları yanıtlanmaz.', ['n' => $n]) : null,
            in_array($unit, ['postfix', 'dovecot'], true) => ($n = Mailbox::count()) ? ($unit === 'postfix' ? __(':n posta kutusu posta gönderip alamaz.', ['n' => $n]) : __(':n posta kutusu postalarına ulaşamaz.', ['n' => $n])) : null,
            $unit === 'rspamd', $unit === 'redis-server@serfex-mail' => Mailbox::count() ? __('Gelen postalar spam denetiminden geçemez ve bekler.') : null,
            $unit === 'clamav-daemon' => Mailbox::count() ? __('Postalar virüs taramasından geçemez.') : null,
            $unit === 'memcached' => __('Memcached kullanan uygulamalar önbelleğe ulaşamaz.'),
            $unit === 'supervisor' => __('Supervisor\'daki programlar durur.'),
            $unit === 'cron' => __('Zamanlanmış görevler çalışmaz.'),
            default => null,
        };

        return $text;
    }

    /**
     * The confirmation of a stop or restart.
     *
     * @param  array<string, mixed>  $service
     * @return array{title: string, text: string, button: string, danger: bool}
     */
    public function confirmation(array $service, string $action): array
    {
        $name = $service['name'] ?? $service['unit'];
        $affects = $this->affects($service['unit']);
        if ($action === 'stop') {
            $boot = ($service['enabled'] ?? '') === 'enabled' ? __(' Sunucu yeniden açılınca kendiliğinden başlar.') : '';

            return ['title' => __(':name durdurulsun mu?', ['name' => $name]), 'text' => ($affects ?? __('Bu hizmeti kullanan her şey durur.')).' '.__('Siz başlatana kadar çalışmaz.').$boot, 'button' => __('Durdur'), 'danger' => true];
        }

        return ['title' => __(':name yeniden başlatılsın mı?', ['name' => $name]), 'text' => trim(__('Birkaç saniyelik kesinti olur. :affects Ayar dosyası önce denetlenir; hatalıysa hizmete dokunulmaz.', ['affects' => $affects])), 'button' => __('Yeniden başlat'), 'danger' => false];
    }

    /**
     * Runs an action. Returns the service's new status, or why it failed.
     *
     * @return array<string, mixed>|string
     */
    public function act(string $unit, string $action): array|string
    {
        try {
            $status = (array) $this->agent->call('service.action', ['unit' => $unit, 'action' => $action], 120);
        } catch (AgentException $e) {
            return self::message($e);
        }
        $this->refresh();

        return $status;
    }

    /**
     * The newest journal lines of a service.
     *
     * @return list<string>|string
     */
    public function logs(string $unit, int $lines = 50): array|string
    {
        try {
            return array_values((array) ($this->agent->call('service.logs', ['unit' => $unit, 'lines' => $lines], 30)['lines'] ?? []));
        } catch (AgentException $e) {
            return self::message($e);
        }
    }

    /**
     * A service's settings section as the page edits it.
     *
     * @return array<string, mixed>|string
     */
    public function settings(string $unit, string $section): array|string
    {
        try {
            return (array) ($this->agent->call('service.settings', ['unit' => $unit], 30)[$section] ?? []);
        } catch (AgentException $e) {
            return self::message($e);
        }
    }

    /**
     * Applies a settings section. Returns the new values, or why not.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|string
     */
    public function applySettings(string $unit, string $section, array $values): array|string
    {
        if ($section === 'database') {
            // The page edits a few of the database settings; the ones set
            // by optimization suggestions stay as they are.
            $current = $this->settings($unit, $section);
            if (is_string($current)) {
                return $current;
            }
            $values = array_merge($current, $values);
        }
        try {
            $res = (array) $this->agent->call('service.settings.apply', ['unit' => $unit, $section => $values], 300);
        } catch (AgentException $e) {
            return self::message($e);
        }

        return (array) ($res[$section] ?? []);
    }

    public static function message(AgentException $e): string
    {
        if ($e instanceof AgentUnavailableException) {
            return __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.');
        }
        // The agent's common refusals, in the panel's words.
        $patterns = [
            '/^configuration test failed, .+? was left as it is: (.*)$/s' => __('Ayar dosyası denetimden geçmedi; hizmete dokunulmadı. Hata: $1'),
            '/^the service refused the settings, nothing was changed: (.*)$/s' => __('Hizmet yeni ayarları kabul etmedi; hiçbir şey değişmedi. Hata: $1'),
            '/^(.+) is not running; start it first$/' => __('$1 çalışmıyor; önce başlatın.'),
            '/^(.+) cannot be stopped: .*$/s' => __('$1 durdurulamaz.'),
            '/^.+ is not a service Serfex manages.*$/s' => __('Bu hizmet sunucuda yok.'),
        ];
        foreach ($patterns as $pattern => $text) {
            if (preg_match($pattern, $e->reason)) {
                return preg_replace($pattern, $text, $e->reason);
            }
        }

        return $e->reason;
    }
}
