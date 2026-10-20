<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hosting package: what its customers may use (limits) and do
 * (features). Customers read their package live, so a change applies to
 * all of them at once.
 */
#[Fillable(['name', 'is_default', 'max_sites', 'max_disk_mb', 'max_traffic_gb', 'max_mailboxes', 'max_databases', 'max_laravel_apps', 'max_backups', 'max_ftp_accounts', 'max_cron_jobs', 'max_subdomains', 'max_aliases',
    'php_versions', 'allow_laravel', 'allow_shell', 'allow_mail', 'allow_dns', 'allow_backups', 'allow_security', 'allow_php_settings', 'max_php_memory_mb'])]
class Package extends Model
{
    use HasFactory;

    /** Limits: key => [column, label, unit shown after the number]. */
    public const LIMITS = [
        'sites' => ['max_sites', 'Site', ''],
        'disk' => ['max_disk_mb', 'Disk', 'GB'],
        'traffic' => ['max_traffic_gb', 'Trafik', 'GB/ay'],
        'mailboxes' => ['max_mailboxes', 'Posta kutusu', ''],
        'databases' => ['max_databases', 'Veritabanı', ''],
        'laravel_apps' => ['max_laravel_apps', 'Laravel uygulaması', ''],
        'backups' => ['max_backups', 'Elle alınan yedek', ''],
        'ftp_accounts' => ['max_ftp_accounts', 'FTP hesabı', ''],
        'cron_jobs' => ['max_cron_jobs', 'Zamanlanmış görev', ''],
        'subdomains' => ['max_subdomains', 'Alt alan adı', ''],
        'aliases' => ['max_aliases', 'Ek alan adı ve yönlendirme', ''],
    ];

    /** Features: key => [column, label, explanation]. */
    public const FEATURES = [
        'laravel' => ['allow_laravel', 'Laravel araç seti', 'Laravel uygulaması kurma, dağıtım, kuyruklar ve süreçler'],
        'shell' => ['allow_shell', 'Komut ve Tinker erişimi', 'Artisan, Composer ve npm komutları, tarayıcıda Tinker'],
        'mail' => ['allow_mail', 'E-posta', 'Posta kutuları, yönlendiriciler ve webmail'],
        'dns' => ['allow_dns', 'DNS düzenleme', 'Alan adlarının DNS kayıtlarını görme ve değiştirme'],
        'backups' => ['allow_backups', 'Yedekler', 'Sitelerin zamanlanmış yedeklere girmesi, yedek alma, geri yükleme ve indirme'],
        'security' => ['allow_security', 'Site güvenliği', 'WAF kipi ve kural istisnaları, güvenlik taraması ve düzeltmeleri, parola korumalı klasörler'],
        'php' => ['allow_php_settings', 'PHP ayarları', 'Sitelerin php.ini değerleri, özel yönergeler ve PHP-FPM havuzu (bellek sınırı paketteki üst sınırı aşamaz)'],
    ];

    protected $attributes = [
        'is_default' => false,
        'allow_laravel' => true,
        'allow_shell' => false,
        'allow_mail' => true,
        'allow_dns' => true,
        'allow_backups' => true,
        'allow_security' => true,
        'allow_php_settings' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'php_versions' => 'array',
            'allow_laravel' => 'boolean',
            'allow_shell' => 'boolean',
            'allow_mail' => 'boolean',
            'allow_dns' => 'boolean',
            'allow_backups' => 'boolean',
            'allow_security' => 'boolean',
            'allow_php_settings' => 'boolean',
            'max_php_memory_mb' => 'integer',
        ];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->first() ?? static::orderBy('id')->first();
    }

    /**
     * A limit in the unit usage is counted in (bytes for disk and
     * traffic); null is unlimited.
     */
    public function limit(string $key): ?int
    {
        $value = $this->{self::LIMITS[$key][0]};
        if ($value === null) {
            return null;
        }

        return match ($key) {
            'disk' => (int) $value * 1024 * 1024,
            'traffic' => (int) $value * 1024 ** 3,
            default => (int) $value,
        };
    }

    public function allows(string $feature): bool
    {
        return (bool) $this->{self::FEATURES[$feature][0]};
    }

    /**
     * Of the installed PHP versions, the ones this package's sites may use.
     *
     * @param  list<string>  $installed
     * @return list<string>
     */
    public function phpVersionsFrom(array $installed): array
    {
        if ($this->php_versions === null) {
            return array_values($installed);
        }

        return array_values(array_intersect($installed, $this->php_versions));
    }
}
