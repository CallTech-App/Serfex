<?php

namespace App\Services\Stats;

use App\Models\BackupItem;
use App\Models\Customer;
use App\Models\Database;
use App\Models\LaravelBackup;
use App\Models\Mailbox;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * What a site's or a customer's disk space is made of: web files, mail,
 * databases and application backups, the parts the disk quota counts
 * (Quotas::usage). Copies in the server's own backups are shown apart;
 * they are the hosting provider's and do not count.
 */
class DiskBreakdown
{
    /** Parts: key => [label, hint]. */
    public const PARTS = [
        'web' => ['Web dosyaları', 'Sitenin klasörü: public_html, alt alan adları, günlükler dışındaki her şey'],
        'mail' => ['E-posta', 'Posta kutularındaki iletiler'],
        'databases' => ['Veritabanları', 'Siteye bağlı veritabanlarının tabloları'],
        'backups' => ['Uygulama yedekleri', 'Laravel uygulamasının kendi yedekleri'],
    ];

    /**
     * A site's parts in bytes (null when never measured), and the size of
     * its copies in the server's backups.
     *
     * @return array{parts: array<string, ?int>, total: int, server_backups: int, measured_at: ?Carbon}
     */
    public function site(Site $site): array
    {
        $site->loadMissing('systemUser', 'mailDomain', 'laravelApp');
        $mail = $site->mailDomain ? (int) Mailbox::where('mail_domain_id', $site->mailDomain->id)->sum('usage_bytes') : 0;
        $parts = [
            'web' => $site->disk_bytes === null ? null : (int) $site->disk_bytes,
            'mail' => $mail,
            'databases' => (int) Database::where('site_id', $site->id)->sum('size_bytes'),
            'backups' => $site->laravelApp ? (int) LaravelBackup::where('laravel_app_id', $site->laravelApp->id)->sum('size') : 0,
        ];

        return [
            'parts' => $parts,
            'total' => array_sum(array_map('intval', $parts)),
            'server_backups' => (int) BackupItem::where('site_id', $site->id)->where('status', 'succeeded')->whereHas('backup', fn ($q) => $q->usable())->sum('size'),
            'measured_at' => $site->systemUser?->disk_measured_at,
        ];
    }

    /**
     * A customer's parts (adding up to the disk quota's usage) and the same
     * parts for each of their sites.
     *
     * @return array{parts: array<string, int>, total: int, other: int, sites: list<array{site: Site, parts: array<string, ?int>, total: int}>}
     */
    public function customer(Customer $customer): array
    {
        $accounts = $customer->systemUsers()->pluck('id');
        $sites = Site::whereIn('system_user_id', $accounts)->whereDoesntHave('preview')->with('systemUser', 'mailDomain', 'laravelApp')->orderBy('domain')->get();
        $siteIds = Site::whereIn('system_user_id', $accounts)->pluck('id');
        $parts = [
            'web' => (int) $customer->systemUsers()->sum('disk_bytes'),
            'mail' => (int) Mailbox::whereHas('domain', fn ($d) => $d->whereIn('site_id', $siteIds))->sum('usage_bytes'),
            'databases' => (int) Database::withTrashed()->whereIn('system_user_id', $accounts)->sum('size_bytes'),
            'backups' => (int) LaravelBackup::whereHas('app', fn ($a) => $a->whereIn('site_id', $siteIds))->sum('size'),
        ];
        $rows = $sites->map(function (Site $site) {
            $s = $this->site($site);

            return ['site' => $site, 'parts' => $s['parts'], 'total' => $s['total']];
        })->all();

        return [
            'parts' => $parts,
            'total' => array_sum($parts),
            // Home directory files outside the sites' folders.
            'other' => max(0, $parts['web'] - (int) $sites->sum('disk_bytes')),
            'sites' => $rows,
        ];
    }
}
