<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\Cache;

/**
 * Looks records up through public DNS, the way other mail servers see
 * them, to tell whether a record written here has reached the internet.
 */
class PublicDns
{
    /** Seconds an answer is reused. */
    public const CACHE_SECONDS = 120;

    private const TYPES = ['A' => DNS_A, 'AAAA' => DNS_AAAA, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'SRV' => DNS_SRV];

    /**
     * Records of $type at $name in the panel's record form: "10 mail.x." for
     * MX, "1 465 host." with the priority first for SRV, host names with a
     * trailing dot. Null when lookups are turned off.
     *
     * @return list<string>|null
     */
    public function lookup(string $name, string $type): ?array
    {
        if (! config('serfex.mail.check_dns')) {
            return null;
        }

        return Cache::remember($this->key($name, $type), self::CACHE_SECONDS, fn () => $this->query($name, $type));
    }

    /**
     * Forget cached answers, for a "check again" button.
     *
     * @param  list<array{0: string, 1: string}>  $pairs  [name, type]
     */
    public function forget(array $pairs): void
    {
        foreach ($pairs as [$name, $type]) {
            Cache::forget($this->key($name, $type));
        }
    }

    /**
     * @return list<string>
     */
    protected function query(string $name, string $type): array
    {
        $records = @dns_get_record($name, self::TYPES[$type] ?? DNS_ANY);
        $out = [];
        foreach ($records ?: [] as $r) {
            if (($r['type'] ?? '') !== $type) {
                continue;
            }
            $out[] = match ($type) {
                'A' => $r['ip'],
                'AAAA' => inet_ntop((string) inet_pton($r['ipv6'])) ?: $r['ipv6'],
                'CNAME' => strtolower($r['target']).'.',
                'MX' => $r['pri'].' '.strtolower($r['target']).'.',
                'SRV' => $r['pri'].' '.$r['weight'].' '.$r['port'].' '.strtolower($r['target']).'.',
                'TXT' => implode('', $r['entries'] ?? [$r['txt']]),
            };
        }

        return $out;
    }

    private function key(string $name, string $type): string
    {
        return 'public-dns:'.$type.':'.$name;
    }
}
