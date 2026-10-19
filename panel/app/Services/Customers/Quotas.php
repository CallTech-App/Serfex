<?php

namespace App\Services\Customers;

use App\Models\Backup;
use App\Models\Customer;
use App\Models\Database;
use App\Models\FtpAccount;
use App\Models\LaravelApp;
use App\Models\LaravelBackup;
use App\Models\Mailbox;
use App\Models\Package;
use App\Models\Site;
use App\Models\SiteTraffic;
use App\Support\Bytes;
use Illuminate\Support\Facades\Cache;

/**
 * What a customer uses against their package, and whether they may create
 * one more of something.
 *
 * Counts (sites, mailboxes, databases, Laravel applications, FTP accounts) are refused at the
 * limit. Disk is measured, not capped by the file system: once it is full
 * nothing new is created (sites, mailboxes, applications, previews,
 * backups) but everything that exists keeps working. Traffic is reported
 * only. Preview environments are not counted as sites; they have their
 * own limit per application.
 */
class Quotas
{
    /** From this share of the disk or traffic limit on, usage shows orange. */
    public const WARN = 0.85;

    /** What each creation counts against. */
    private const KINDS = [
        'sites' => 'site sınırına',
        'mailboxes' => 'posta kutusu sınırına',
        'databases' => 'veritabanı sınırına',
        'laravel_apps' => 'Laravel uygulaması sınırına',
        'backups' => 'yedek sınırına',
        'ftp_accounts' => 'FTP hesabı sınırına',
    ];

    /** Kinds that take no disk space, so a full disk does not refuse them. */
    private const DISKLESS = ['ftp_accounts'];

    /**
     * Usage in the unit limits use (bytes for disk and traffic). Disk is
     * the home directories, mail, Laravel backups and databases.
     *
     * @return array<string, int|null>
     */
    public function usage(Customer $customer): array
    {
        $accounts = $customer->systemUsers()->pluck('id');
        $sites = Site::whereIn('system_user_id', $accounts)->whereDoesntHave('preview');
        $siteIds = Site::whereIn('system_user_id', $accounts)->pluck('id');
        $mailboxes = Mailbox::whereHas('domain', fn ($d) => $d->whereIn('site_id', $siteIds));
        $apps = LaravelApp::whereIn('site_id', $siteIds);

        $disk = (int) $customer->systemUsers()->sum('disk_bytes')
            + (int) (clone $mailboxes)->sum('usage_bytes')
            + (int) LaravelBackup::whereIn('laravel_app_id', (clone $apps)->select('id'))->sum('size')
            + (int) Database::withTrashed()->whereIn('system_user_id', $accounts)->sum('size_bytes');

        return [
            'sites' => $sites->count(),
            'disk' => $disk,
            'traffic' => (int) SiteTraffic::whereIn('site_id', $siteIds)->where('month', now()->startOfMonth()->toDateString())->sum('bytes'),
            'mailboxes' => $mailboxes->count(),
            'databases' => Database::whereIn('system_user_id', $accounts)->count(),
            'laravel_apps' => $apps->whereNull('preview_of_id')->count(),
            // Backups they took themselves; scheduled ones are the server's.
            'backups' => Backup::where('customer_id', $customer->id)->where('trigger', 'manual')->whereNotIn('status', ['failed', 'deleted'])->count(),
            'ftp_accounts' => FtpAccount::whereIn('system_user_id', $accounts)->count(),
        ];
    }

    /**
     * Every limit with its usage and how close it is: level ok, warn
     * (disk and traffic from 85 %) or crit (disk and traffic at or over
     * the limit, counts over it).
     *
     * @return array<string, array{label: string, used: int|null, limit: int|null, ratio: float|null, level: string, text: string}>
     */
    public function report(Customer $customer, ?array $usage = null): array
    {
        $usage ??= $this->usage($customer);
        $package = $customer->package;
        $out = [];
        foreach (Package::LIMITS as $key => [, $label]) {
            $used = $usage[$key];
            $limit = $package->limit($key);
            $ratio = $limit === null || $used === null ? null : ($limit === 0 ? ($used > 0 ? INF : 0.0) : $used / $limit);
            $bytes = in_array($key, ['disk', 'traffic'], true);
            // A count at its limit is the package used as bought; only
            // more than it (after a downgrade) needs a look.
            $level = match (true) {
                $ratio === null => 'ok',
                $bytes && $ratio >= 1, ! $bytes && $ratio > 1 => 'crit',
                $bytes && $ratio >= self::WARN && $limit > 0 => 'warn',
                default => 'ok',
            };
            $out[$key] = [
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'ratio' => $ratio,
                'level' => $level,
                'text' => $this->text($key, $used, $limit),
            ];
        }

        return $out;
    }

    /**
     * The worst level of a customer's limits.
     *
     * @param  array<string, array{level: string}>  $report
     */
    public static function worst(array $report): string
    {
        $levels = array_column($report, 'level');

        return in_array('crit', $levels, true) ? 'crit' : (in_array('warn', $levels, true) ? 'warn' : 'ok');
    }

    /**
     * Why the customer may not create one more $kind (sites, mailboxes,
     * laravel_apps, or disk for things that only take space), or null
     * when they may.
     */
    public function refusal(?Customer $customer, string $kind): ?string
    {
        if ($customer === null) {
            return null;
        }
        if ($customer->isSuspended()) {
            return 'Hesap askıya alınmış; yeni bir şey oluşturulamaz.';
        }
        $report = $this->report($customer);
        if (isset(self::KINDS[$kind])) {
            $limit = $report[$kind]['limit'];
            if ($limit !== null && $report[$kind]['used'] >= $limit) {
                return 'Paketin '.self::KINDS[$kind].' ulaşıldı ('.$report[$kind]['text'].'). Paketi yükseltin ya da kullanılmayanları silin.';
            }
        }
        if ($report['disk']['level'] === 'crit' && ! in_array($kind, self::DISKLESS, true)) {
            return 'Disk kotası doldu ('.$report['disk']['text'].'). Yer açın ya da paketi yükseltin.';
        }

        return null;
    }

    /**
     * "2 / 5", "18,2 GB / 20 GB", "3 / Sınırsız".
     */
    public function text(string $key, ?int $used, ?int $limit): string
    {
        $bytes = in_array($key, ['disk', 'traffic'], true);
        $fmt = fn (int $n) => $bytes ? Bytes::format($n) : number_format($n, 0, ',', '.');

        return ($used === null ? '—' : $fmt($used)).' / '.($limit === null ? 'Sınırsız' : $fmt($limit));
    }

    /**
     * Customers whose usage needs a look (warn or crit), for the menu and
     * the dashboard. Counted at most once a minute.
     */
    public function attentionCount(): int
    {
        return Cache::remember('servik.customers.attention', 60, fn () => Customer::with('package')->get()
            ->filter(fn (Customer $c) => self::worst($this->report($c)) !== 'ok')->count());
    }

    /**
     * Drops the cached count after usage or limits changed.
     */
    public static function forget(): void
    {
        Cache::forget('servik.customers.attention');
    }
}
