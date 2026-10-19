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
#[Fillable(['name', 'is_default', 'max_sites', 'max_disk_mb', 'max_traffic_gb', 'max_mailboxes', 'max_databases', 'max_laravel_apps',
    'php_versions', 'allow_laravel', 'allow_shell', 'allow_mail', 'allow_dns'])]
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
    ];

    /** Features: key => [column, label, explanation]. */
    public const FEATURES = [
        'laravel' => ['allow_laravel', 'Laravel araç seti', 'Laravel uygulaması kurma, dağıtım, kuyruklar ve süreçler'],
        'shell' => ['allow_shell', 'Komut ve Tinker erişimi', 'Artisan, Composer ve npm komutları, tarayıcıda Tinker'],
        'mail' => ['allow_mail', 'E-posta', 'Posta kutuları, yönlendiriciler ve webmail'],
        'dns' => ['allow_dns', 'DNS düzenleme', 'Alan adlarının DNS kayıtlarını görme ve değiştirme'],
    ];

    protected $attributes = [
        'is_default' => false,
        'allow_laravel' => true,
        'allow_shell' => false,
        'allow_mail' => true,
        'allow_dns' => true,
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
