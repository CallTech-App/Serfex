<?php

namespace App\Services\Mail;

use App\Services\Dns\DnsSettings;
use Illuminate\Support\Facades\Cache;

/**
 * The server's reverse DNS (PTR) record, as other mail servers check it.
 * Gmail, Outlook and most others refuse mail from an IP address whose PTR
 * record is missing or names a host that doesn't point back at it, so
 * mail sent by this server's Postfix bounces while the panel thinks it
 * went out. The record is set at the hosting company, not here.
 */
class ReverseDns
{
    /** Seconds an answer is reused. */
    public const CACHE_SECONDS = 600;

    public function __construct(private readonly DnsSettings $dns) {}

    /**
     * Why mail from this server's IPv4 address is likely refused, or null
     * when the PTR record is right, unknown, or lookups are turned off.
     */
    public function problem(): ?string
    {
        $ip = $this->dns->ipv4();
        if (! config('serfex.mail.check_dns') || $ip === null || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        [$name, $back] = Cache::remember('reverse-dns|'.$ip, self::CACHE_SECONDS, fn () => [$host = $this->ptr($ip), $host === null ? [] : $this->addresses($host)]);
        if ($name === null) {
            return __(':ip adresinin ters DNS (PTR) kaydı yok. Gmail ve Outlook bu sunucudan gönderilen e-postaları reddeder. Kaydı sunucuyu kiraladığınız firmanın panelinden sunucunun adına ayarlayın ya da bir SMTP sunucusu girin.', ['ip' => $ip]);
        }
        if (! in_array($ip, $back, true)) {
            return __(':ip adresinin ters DNS (PTR) kaydı :name adını gösteriyor, ama bu adın A kaydı :ip değil. Gmail ve Outlook bu sunucudan gönderilen e-postaları reddeder. :name için :ip adresine bir A kaydı ekleyin ya da PTR kaydını düzeltin.', ['ip' => $ip, 'name' => $name]);
        }

        return null;
    }

    /**
     * Forget the cached answer, for a "check again" after fixing it.
     */
    public function forget(): void
    {
        if ($ip = $this->dns->ipv4()) {
            Cache::forget('reverse-dns|'.$ip);
        }
    }

    /**
     * The host name the PTR record of $ip names, without the trailing dot.
     */
    protected function ptr(string $ip): ?string
    {
        $records = @dns_get_record(implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa', DNS_PTR);
        foreach ($records ?: [] as $r) {
            if (($r['type'] ?? '') === 'PTR' && ($r['target'] ?? '') !== '') {
                return rtrim(strtolower($r['target']), '.');
            }
        }

        return null;
    }

    /**
     * @return list<string> the IPv4 addresses $host points at
     */
    protected function addresses(string $host): array
    {
        return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? null, @dns_get_record($host, DNS_A) ?: [])));
    }
}
