<?php

namespace App\Services\Files;

use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Quotas;
use Illuminate\Support\Str;

/**
 * The file manager of a site. The agent does the work in a helper that
 * runs as the site's own user and never leaves the site's folder
 * (/home/<account>/web/<domain>); this class names the site and turns the
 * agent's refusals into sentences.
 *
 * Deleted entries, versions the editor saved over and files an upload or
 * an extraction replaced go to the site's trash for TRASH_DAYS, which is
 * what every Undo here restores from.
 */
class FileManager
{
    /** Agent error code for file operations. */
    public const ERROR = -32008;

    /** Bytes per upload request and per download read (the agent's chunk). */
    public const CHUNK = 4 * 1024 * 1024;

    public const MAX_UPLOAD = 2 * 1024 ** 3;

    public const MAX_EDIT = 2 * 1024 * 1024;

    public const TRASH_DAYS = 7;

    /** Files the editor opens by name; others by trying (text only). */
    public const EDITABLE = ['php', 'phtml', 'html', 'htm', 'css', 'scss', 'less', 'js', 'mjs', 'cjs', 'ts', 'jsx', 'tsx', 'vue', 'json', 'xml', 'svg', 'txt', 'md', 'markdown',
        'env', 'ini', 'conf', 'cnf', 'cfg', 'yml', 'yaml', 'toml', 'neon', 'htaccess', 'htpasswd', 'log', 'sql', 'csv', 'tsv', 'twig', 'tpl', 'sh', 'py', 'rb', 'pl', 'lock', 'gitignore',
        'gitattributes', 'editorconfig', 'example', 'dist', 'map', 'po', 'pot', 'rst', 'webmanifest'];

    public const ARCHIVES = ['zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz2'];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Runs one operation on the site's files.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function call(Site $site, string $op, array $params = [], ?float $timeout = null): array
    {
        $site->loadMissing('systemUser');

        return (array) $this->agent->call('files.'.$op, ['user' => $site->systemUser->name, 'domain' => $site->domain] + $params, $timeout ?? config('serfex.agent.job_timeout'));
    }

    /**
     * The site's folder on the server.
     */
    public static function root(Site $site): string
    {
        return '/home/'.$site->systemUser->name.'/web/'.$site->domain;
    }

    /**
     * Why the site's owner may not add data now (full disk), or null.
     */
    public function uploadRefusal(Site $site): ?string
    {
        return app(Quotas::class)->refusal($site->customer(), 'disk');
    }

    public static function editable(string $name): bool
    {
        $lower = Str::lower($name);
        if (str_ends_with($lower, '.blade.php')) {
            return true;
        }
        $ext = str_contains($lower, '.') ? Str::afterLast($lower, '.') : '';

        // Names without an extension (Makefile, .env) are tried as text.
        return $ext === '' || str_starts_with($lower, '.') || in_array($ext, self::EDITABLE, true);
    }

    public static function isArchive(string $name): bool
    {
        return (bool) preg_match('/\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2)$/i', $name);
    }

    /**
     * A sentence for an agent refusal ("exists: public_html/a.txt: ...").
     */
    public static function message(AgentException $e): string
    {
        if ($e->getCode() !== self::ERROR) {
            return $e->reason !== '' ? __('Sunucu işlemi tamamlayamadı: :reason', ['reason' => $e->reason]) : __('Sunucu ajanına ulaşılamıyor.');
        }
        $parts = explode(': ', $e->reason, 3);
        $code = $parts[0];
        $path = count($parts) === 3 ? $parts[1] : '';
        $name = $path !== '' ? '"'.basename($path).'"' : '';

        return match ($code) {
            'exists' => trim(__(':name zaten var.', ['name' => ($name ?: __('Bu ad'))])),
            'not_found' => trim(__(':name bulunamadı; başka biri silmiş ya da taşımış olabilir.', ['name' => $name ?: __('Dosya')])),
            'not_dir' => trim(__(':name bir klasör değil.', ['name' => $name])),
            'is_dir' => trim(__(':name bir klasör; dosya olarak açılamaz.', ['name' => $name])),
            'outside' => __('Bu yol site klasörünün dışına çıkıyor; izin verilmez.'),
            'invalid_name' => __('Ad geçersiz: "/" içeremez, boş olamaz ve en fazla 255 bayt olabilir.'),
            'binary' => trim(__(':name metin dosyası değil; düzenleyicide açılamaz. İndirebilirsiniz.', ['name' => $name])),
            'too_big' => trim(__(':name çok büyük.', ['name' => $name])),
            'conflict' => __('Dosya siz düzenlerken değişti. Kaydetmeden önce yeniden açın.'),
            'denied' => __('İzin yok: site kullanıcısı bu dosyayı değiştiremiyor (sahibi başka bir kullanıcı olabilir).'),
            'archive' => __('Arşiv açılamadı: yalnızca zip, tar, tar.gz ve tar.bz2 desteklenir ve dosya bozuk olmamalı.'),
            'too_many' => __('Arşivde çok fazla dosya var.'),
            'into_itself' => __('Bir klasör kendi içine taşınamaz ya da kopyalanamaz.'),
            'no_space' => __('Diskte yer kalmadı.'),
            'quota' => __('Disk kotası doldu: paketin disk alanının tamamı kullanılıyor. Yer açın ya da paketi yükseltin.'),
            'invalid' => __('Geçersiz istek: :parts', ['parts' => ($parts[count($parts) - 1] ?? '')]),
            default => __('İşlem tamamlanamadı: :reason', ['reason' => ($parts[count($parts) - 1] ?? $e->reason)]),
        };
    }
}
