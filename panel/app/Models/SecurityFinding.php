<?php

namespace App\Models;

use App\Support\Badge;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A problem the security scan found: in a site (site_id) or in the server
 * and the panel's own settings (no site). The same problem keeps its row
 * across scans (fingerprint); it is open until fixed, ignored, or no
 * longer found.
 */
#[Fillable(['scan_id', 'site_id', 'check', 'code', 'severity', 'path', 'url', 'detail', 'fixes', 'count', 'fingerprint',
    'status', 'fix_action', 'fix_data', 'resolved_by', 'resolved_at'])]
class SecurityFinding extends Model
{
    /** Severities from the worst, with their labels and colours. */
    public const SEVERITIES = [
        'critical' => ['Kritik', 'crit'],
        'high' => ['Yüksek', 'crit'],
        'medium' => ['Orta', 'warn'],
        'low' => ['Düşük', 'muted'],
    ];

    /** Fixes: action => [button, done]. */
    public const FIXES = [
        'quarantine' => ['Karantinaya al', 'Karantinaya alındı'],
        'permissions' => ['İzinleri düzelt', 'İzinler düzeltildi'],
        'owner' => ['Sahibini düzelt', 'Sahibi düzeltildi'],
        'deny' => ['Erişimi engelle', 'Erişim engellendi'],
    ];

    protected $attributes = [
        'path' => '',
        'status' => 'open',
        'count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'fixes' => 'array',
            'fix_data' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<SecurityFinding>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }

    /**
     * Worst first, then by site and path.
     *
     * @param  Builder<SecurityFinding>  $query
     */
    public function scopeWorstFirst(Builder $query): void
    {
        $query->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->orderBy('site_id')->orderBy('path');
    }

    /**
     * Findings the user may see: all for an administrator, their own
     * sites' for a customer.
     *
     * @param  Builder<SecurityFinding>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->is_admin) {
            $query->whereNotNull('site_id')->whereIn('site_id', Site::visibleTo($user)->select('id'));
        }
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<SecurityScan, $this>
     */
    public function scan(): BelongsTo
    {
        return $this->belongsTo(SecurityScan::class, 'scan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function severityBadge(): Badge
    {
        [$label, $tone] = self::SEVERITIES[$this->severity] ?? self::SEVERITIES['low'];

        return new Badge($label, $tone);
    }

    public function isServer(): bool
    {
        return $this->site_id === null;
    }

    /** Checks: check => what it looks for. */
    public const CHECKS = [
        'malware' => 'Virüs',
        'webshell' => 'Arka kapı',
        'suspicious' => 'Şüpheli kod',
        'writable' => 'Yazma izni',
        'owner' => 'Dosya sahibi',
        'exposed' => 'Açıkta kalan dosya',
        'outdated' => 'Eski yazılım',
        'debug' => 'Hata ayıklama',
        'server' => 'Sunucu ayarı',
    ];

    /**
     * What the problem is, in a sentence.
     */
    public function title(): string
    {
        if (str_ends_with($this->code, '-more')) {
            return (self::CHECKS[$this->check] ?? 'Aynı tür').': '.$this->count.' sorun daha (ilk 50 tanesi listede)';
        }
        $served = $this->detail !== 'not served';
        $kind = $this->code === 'dir' ? 'klasör' : 'dosya';

        return match ($this->check.'/'.$this->code) {
            'malware/clamav' => 'Virüs imzası bulundu',
            'webshell/eval-decoded' => 'Gizlenmiş kod çalıştıran PHP dosyası',
            'webshell/exec-input' => 'Ziyaretçinin gönderdiği komutu çalıştıran PHP dosyası',
            'webshell/call-input' => 'Ziyaretçinin seçtiği işlevi çağıran PHP dosyası',
            'webshell/known-shell' => 'Bilinen bir web kabuğu (web shell)',
            'webshell/backtick-input' => 'Ziyaretçi girdisini kabukta çalıştıran PHP dosyası',
            'webshell/php-in-image', 'suspicious/php-in-image' => 'Resim uzantılı ama PHP kodu içeren dosya',
            'webshell/preg-eval', 'suspicious/preg-eval' => 'Kod çalıştıran eski preg_replace /e kullanımı',
            'webshell/encoded-blob', 'suspicious/encoded-blob' => 'Uzun şifrelenmiş blok içeren PHP dosyası',
            'webshell/uploader', 'suspicious/uploader' => 'Kimlik doğrulamasız dosya yükleyici',
            'suspicious/php-in-uploads' => 'Yükleme klasöründe PHP dosyası',
            'writable/file', 'writable/dir' => 'Sunucudaki herkesin değiştirebildiği '.$kind,
            'owner/file', 'owner/dir' => 'Site hesabına ait olmayan '.$kind,
            'exposed/env' => $served ? '.env dosyası internetten okunabiliyor' : '.env dosyası web klasöründe',
            'exposed/git' => $served ? '.git klasörü internetten okunabiliyor' : '.git klasörü web klasöründe',
            'exposed/backup' => $served ? 'Yedek ya da döküm dosyası internetten indirilebiliyor' : 'Yedek ya da döküm dosyası web klasöründe',
            'exposed/phpinfo' => 'phpinfo() sayfası sunucu bilgilerini gösteriyor',
            'outdated/wordpress' => 'WordPress sürümü güvenlik desteği dışında',
            'outdated/laravel' => 'Laravel sürümü güvenlik desteği dışında',
            'outdated/php' => 'Site eski bir PHP sürümünde',
            'debug/app-debug' => 'Laravel hata ayıklama modu canlıda açık',
            'server/no-2fa' => 'Hiçbir yöneticide iki adımlı doğrulama yok',
            'server/firewall-off' => 'Port güvenlik duvarı kapalı',
            'server/ssh-root-password' => 'root kullanıcısı SSH ile parolayla girebiliyor',
            'server/ssh-password' => 'SSH parolayla girişe açık',
            'server/waf-missing' => 'Web uygulaması güvenlik duvarı (ModSecurity) kurulu değil',
            'server/fail2ban-missing' => 'Fail2ban kurulu değil; panel ve SSH girişleri korunmuyor',
            'server/panel-debug' => 'Panelin hata ayıklama modu açık',
            default => (self::CHECKS[$this->check] ?? 'Sorun').': '.$this->code,
        };
    }

    /**
     * The detail worth showing next to the title, in Turkish.
     */
    public function detailText(): ?string
    {
        return match (true) {
            $this->detail === null, $this->detail === 'not served' => null,
            $this->check === 'writable' => 'İzinler '.$this->detail,
            $this->check === 'owner' => 'Sahibi '.$this->detail,
            $this->check === 'debug' => $this->detail,
            default => $this->detail,
        };
    }

    /**
     * Where an administrator fixes a server finding by hand.
     */
    public function settingsLink(): ?string
    {
        return match ($this->code) {
            'no-2fa' => route('account.security'),
            'firewall-off' => route('security.index', ['sekme' => 'guvenlik-duvari']),
            'ssh-root-password', 'ssh-password' => route('security.index', ['sekme' => 'giris']),
            'waf-missing', 'fail2ban-missing' => route('components.index', ['sekme' => 'diger']),
            default => null,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'fixed' => self::FIXES[$this->fix_action][1] ?? 'Düzeltildi',
            'ignored' => 'Yok sayıldı',
            'gone' => 'Artık bulunmuyor',
            default => 'Açık',
        };
    }
}
