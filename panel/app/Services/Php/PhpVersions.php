<?php

namespace App\Services\Php;

use App\Jobs\RunComponentOperation;
use App\Models\ComponentOperation;
use App\Models\PanelUpdate;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use Illuminate\Support\Str;

/**
 * PHP versions (Hizmetler → PHP sürümleri): the server's default for new
 * sites, each version's php.ini defaults and extensions, and the PHP
 * settings and PHP-FPM pool of each site. Installing and removing versions
 * is a component operation like in Yazılım Yönetimi.
 */
class PhpVersions
{
    /**
     * Server-wide php.ini settings shown per version:
     * key => [label, type, help]. Types: size (128M), seconds, int, onoff,
     * flag (1/0), text.
     */
    public const INI = [
        'memory_limit' => ['Bellek sınırı', 'size', 'Bir isteğin kullanabileceği en fazla bellek.'],
        'upload_max_filesize' => ['En büyük dosya yükleme', 'size', 'Tek bir yüklenen dosyanın boyutu.'],
        'post_max_size' => ['En büyük form gönderimi', 'size', 'Dosyalar dahil bir isteğin tamamı; yükleme sınırından büyük olmalı.'],
        'max_execution_time' => ['En uzun çalışma süresi', 'seconds', 'Saniye; 0 sınırsız.'],
        'max_input_time' => ['En uzun girdi okuma süresi', 'seconds', 'Saniye; büyük yüklemelerde artırın.'],
        'max_input_vars' => ['En fazla form alanı', 'int', 'Büyük menü ve ürün formları için 3000 ve üstü önerilir.'],
        'max_file_uploads' => ['Tek istekte en fazla dosya', 'int', ''],
        'display_errors' => ['Hataları sayfada göster', 'onoff', 'Canlı sitelerde kapalı tutun; hatalar günlüğe yazılır.'],
        'allow_url_fopen' => ['Uzak adresleri dosya gibi açma', 'onoff', 'file_get_contents("https://...") gibi çağrılar.'],
        'date.timezone' => ['Saat dilimi', 'text', 'Örneğin Europe/Istanbul.'],
        'session.gc_maxlifetime' => ['Oturum ömrü', 'seconds', 'Saniye.'],
        'opcache.enable' => ['OPcache', 'flag', 'Derlenmiş PHP kodunu bellekte tutar; siteleri belirgin hızlandırır.'],
        'opcache.memory_consumption' => ['OPcache belleği', 'int', 'MB.'],
        'opcache.interned_strings_buffer' => ['OPcache metin belleği', 'int', 'MB.'],
        'opcache.max_accelerated_files' => ['OPcache dosya sayısı', 'int', 'Büyük uygulamalarda 20000 ve üstü.'],
        'opcache.validate_timestamps' => ['Değişen dosyaları denetle', 'flag', 'Kapalıysa kod değişince PHP-FPM yeniden başlatılmalı.'],
        'opcache.revalidate_freq' => ['Denetleme aralığı', 'seconds', 'Saniye.'],
    ];

    /** Settings a site may override: key => [label, type]. */
    public const SITE_INI = [
        'memory_limit' => ['Bellek sınırı', 'size'],
        'upload_max_filesize' => ['En büyük dosya yükleme', 'size'],
        'post_max_size' => ['En büyük form gönderimi', 'size'],
        'max_execution_time' => ['En uzun çalışma süresi', 'seconds'],
        'max_input_time' => ['En uzun girdi okuma süresi', 'seconds'],
        'max_input_vars' => ['En fazla form alanı', 'int'],
        'display_errors' => ['Hataları sayfada göster', 'onoff'],
    ];

    /** Functions customers' sites run without, unless allowed per site. */
    public const DANGEROUS = ['exec', 'passthru', 'shell_exec', 'system', 'proc_open', 'popen', 'pcntl_exec', 'show_source'];

    /** Settings Servik sets for every pool; never changeable. */
    public const RESERVED = ['open_basedir', 'upload_tmp_dir', 'sys_temp_dir', 'session.save_path', 'error_log', 'log_errors',
        'disable_functions', 'disable_classes', 'extension', 'zend_extension', 'extension_dir', 'doc_root', 'user_dir'];

    /** Process managers: key => [label, explanation]. */
    public const PM = [
        'ondemand' => ['İstek geldikçe (önerilen)', 'İşçiler istekle başlar, boşta kalınca kapanır; az bellek kullanır.'],
        'dynamic' => ['Hazırda bekleyen', 'Birkaç işçi hep hazır bekler; yoğun sitelerde ilk istek daha hızlı.'],
        'static' => ['Sabit sayıda', 'En fazla işçi sayısı kadar işçi hep çalışır; en çok bellek bunda gider.'],
    ];

    public const DEFAULT_MAX_CHILDREN = 10;

    /** Most PHP workers a customer may give a site. */
    public const CUSTOMER_MAX_CHILDREN = 20;

    public const SIZE_PATTERN = '/^(-1|[1-9]\d{0,5}[KMG]?)$/i';

    /** A php.ini name and value the agent accepts. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$/';

    public const VALUE_PATTERN = '/^[A-Za-z0-9_.,:\/ +*@~=%-]*$/';

    /** @var list<string>|null */
    private ?array $installed = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Components $components,
    ) {}

    /**
     * Installed PHP versions, newest first; null when the agent cannot be
     * reached.
     *
     * @return list<string>|null
     */
    public function installed(): ?array
    {
        if ($this->installed !== null) {
            return $this->installed;
        }
        try {
            $versions = array_values((array) ($this->agent->call('php.versions') ?? []));
        } catch (AgentException) {
            return null;
        }
        usort($versions, fn ($a, $b) => version_compare($b, $a));

        return $this->installed = $versions;
    }

    /**
     * The version new sites get: the one chosen on the PHP page while it
     * is installed, else the newest installed one.
     *
     * @param  list<string>|null  $available  versions to choose from (a package's)
     */
    public function default(?array $available = null): ?string
    {
        $available ??= $this->installed() ?? [];
        usort($available, fn ($a, $b) => version_compare($b, $a));
        $chosen = Setting::get('php.default_version');

        return in_array($chosen, $available, true) ? $chosen : ($available[0] ?? null);
    }

    /**
     * Functions switched off for customers' sites.
     *
     * @return list<string>
     */
    public function disabledFunctions(): array
    {
        return Setting::get('php.disabled_functions', self::DANGEROUS);
    }

    /**
     * A version's php.ini values, extensions and Servik's defaults.
     *
     * @return array<string, mixed>
     */
    public function info(string $version): array
    {
        return (array) $this->agent->call('php.info', ['version' => $version]);
    }

    /**
     * The rows of the PHP versions table (Components, plus the default).
     *
     * @return list<array<string, mixed>>|null
     */
    public function rows(?string &$error = null): ?array
    {
        $groups = $this->components->rows('php', $error);
        if ($groups === null) {
            return null;
        }
        $default = $this->default(array_values(array_map(fn ($r) => substr($r['id'], 3), array_filter($groups[''] ?? [], fn ($r) => $r['installed']))));

        return array_map(fn ($r) => [
            ...$r,
            'number' => substr($r['id'], 3),
            'sites' => Site::where('php_version', substr($r['id'], 3))->orderBy('domain')->pluck('domain')->all(),
            'default' => $r['installed'] && substr($r['id'], 3) === $default,
        ], $groups[''] ?? []);
    }

    /**
     * Starts installing or removing an optional extension. Returns the
     * operation, or why it cannot start.
     */
    public function startExtension(string $version, string $name, bool $remove, ?User $user): ComponentOperation|string
    {
        if (! preg_match('/^\d\.\d$/', $version) || ! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name)) {
            return __('Bu eklenti buradan yönetilemiyor.');
        }
        if (ComponentOperation::running()->exists() || $this->components->databaseSetupRunning()) {
            return __('Başka bir kurulum sürüyor. Bitince yeniden deneyin.');
        }
        if (PanelUpdate::running()->exists()) {
            return __('Servik güncelleniyor. Bitince yeniden deneyin.');
        }
        $operation = ComponentOperation::create([
            'component' => 'php'.$version,
            'action' => $remove ? 'ext-remove' : 'ext-install',
            'detail' => $name,
            'run' => 'c'.Str::lower(Str::random(23)),
            'user_id' => $user?->id,
        ]);
        RunComponentOperation::dispatch($operation);

        return $operation;
    }

    /**
     * Megabytes of a php.ini size such as 128M or 1G; null for -1 or
     * nonsense.
     */
    public static function megabytes(?string $value): ?int
    {
        if ($value === null || ! preg_match('/^(\d+)([KMG]?)$/i', trim($value), $m)) {
            return null;
        }

        return match (strtoupper($m[2])) {
            'G' => (int) $m[1] * 1024,
            'M' => (int) $m[1],
            'K' => intdiv((int) $m[1], 1024),
            default => intdiv((int) $m[1], 1024 * 1024),
        };
    }

    /**
     * Parses custom directives, one "name = value" per line. Returns the
     * directives, or the first problem as a message.
     *
     * @return array<string, string>|string
     */
    public static function parseCustom(string $text, bool $customer): array|string
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                return __(':i. satır "ad = değer" biçiminde değil.', ['i' => ($i + 1)]);
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, '"');
            if (! preg_match(self::KEY_PATTERN, $key) || strlen($key) > 64) {
                return __('":key" geçerli bir php.ini adı değil.', ['key' => $key]);
            }
            if (in_array($key, self::RESERVED, true) || ($customer && in_array($key, ['auto_prepend_file', 'auto_append_file'], true))) {
                return __(':key Servik tarafından ayarlanır, değiştirilemez.', ['key' => $key]);
            }
            if (array_key_exists($key, self::SITE_INI)) {
                return __(':key için yukarıdaki alanı kullanın.', ['key' => $key]);
            }
            if (! preg_match(self::VALUE_PATTERN, $value) || strlen($value) > 200) {
                return __(':key değeri yalnızca harf, rakam, boşluk ve . , : / + * @ ~ = % _ - içerebilir.', ['key' => $key]);
            }
            $out[$key] = $value;
        }
        if (count($out) > 30) {
            return __('En fazla 30 özel yönerge eklenebilir.');
        }

        return $out;
    }

    /**
     * What the agent's site.apply gets for the site's PHP: its own
     * settings, the package's memory cap and the functions customers'
     * sites run without. Null keeps the server's defaults.
     *
     * @return array<string, mixed>|null
     */
    public function siteSpec(Site $site): ?array
    {
        $s = $site->php_settings ?? [];
        $customer = $site->customer();
        $values = [];
        $admin = [];
        foreach (self::SITE_INI as $key => $_) {
            $v = $s['ini'][$key] ?? null;
            if ($v !== null && $v !== '') {
                $values[$key] = (string) $v;
            }
        }
        $custom = self::parseCustom((string) ($s['custom'] ?? ''), $customer !== null);
        if (is_array($custom)) {
            $values += $custom;
        }
        // A customer's memory limit is a ceiling the site's code cannot
        // raise with ini_set.
        $cap = $customer?->package?->max_php_memory_mb;
        if ($cap !== null) {
            $own = self::megabytes($values['memory_limit'] ?? null);
            unset($values['memory_limit']);
            $admin['memory_limit'] = ($own !== null && $own <= $cap ? $own : min($cap, $this->serverMemory($site->php_version) ?? 256)).'M';
        }
        $spec = [];
        if ($values !== []) {
            $spec['values'] = $values;
        }
        if ($admin !== []) {
            $spec['admin_values'] = $admin;
        }
        if ($customer !== null && ! ($s['allow_functions'] ?? false)) {
            $spec['disable_functions'] = $this->disabledFunctions();
        }
        foreach (['pm', 'max_children', 'max_requests', 'request_timeout'] as $key) {
            if (! empty($s[$key])) {
                $spec[$key] = $key === 'pm' ? (string) $s[$key] : (int) $s[$key];
            }
        }
        if (($spec['pm'] ?? null) === 'ondemand') {
            unset($spec['pm']);
        }
        if (($body = self::megabytes($values['post_max_size'] ?? null)) !== null && $body > 0) {
            $spec['body_mb'] = $body;
        }

        return $spec === [] ? null : $spec;
    }

    /**
     * The memory limit (MB) a version's sites get by default, as last read.
     */
    public function serverMemory(string $version): ?int
    {
        return self::megabytes(Setting::get('php.memory.'.$version));
    }

    /**
     * Remembers a version's default memory limit, for customers' caps.
     *
     * @param  array<string, mixed>  $info  php.info's answer
     */
    public function remember(array $info): void
    {
        if (isset($info['version'], $info['values']['memory_limit'])) {
            Setting::put('php.memory.'.$info['version'], $info['values']['memory_limit']);
        }
    }
}
