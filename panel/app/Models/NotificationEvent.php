<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A problem the panel told the administrators about: once when it
 * appeared and once when it went away.
 */
#[Fillable(['kind', 'key', 'title', 'detail', 'url', 'state', 'sent', 'error', 'opened_at', 'resolved_at'])]
class NotificationEvent extends Model
{
    /** Kinds: key => [name, when the administrators hear about it]. */
    public const KINDS = [
        'certificate' => ['SSL sertifikaları', 'Bir sertifika alınamadığında, yenilenemediğinde ya da süresi dolmak üzereyken'],
        'backup' => ['Yedekler', 'Sunucu yedeği alınamadığında, zamanlanmış yedek başlamadığında ya da hedefe ulaşılamadığında'],
        'update' => ['Güncellemeler', 'Yeni Servik sürümü çıktığında ya da bir güncelleme başarısız olduğunda'],
        'disk' => ['Disk', 'Sunucu diski %90 dolduğunda ya da bir müşteri disk kotasını aştığında'],
        'traffic' => ['Trafik', 'Bir müşteri paketinin aylık trafik sınırını aştığında (siteler çalışmaya devam eder)'],
        'service' => ['Hizmetler', 'Nginx, PHP-FPM, MySQL ya da posta gibi bir hizmet durduğunda'],
    ];

    protected function casts(): array
    {
        return [
            'sent' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function kindName(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }
}
