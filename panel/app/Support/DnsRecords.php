<?php

namespace App\Support;

/**
 * DNS record rules shared with servik-agent (agent/internal/dns/records.go).
 * The agent has the final say; these give forms an early, friendly error
 * and store records in the form the agent expects.
 */
class DnsRecords
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

    /** Types whose priority is kept in its own column. */
    public const WITH_PRIORITY = ['MX', 'SRV'];

    public const MIN_TTL = 60;

    public const MAX_TTL = 604800;

    /** TTL choices offered in forms, in seconds. */
    public const TTLS = [300 => '5 dakika', 3600 => '1 saat', 14400 => '4 saat', 86400 => '1 gün'];

    private const LABEL = '/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/';

    /**
     * Human label and example for the value field of each type.
     *
     * @return array{0: string, 1: string}
     */
    public static function valueField(string $type): array
    {
        return match ($type) {
            'A' => ['IPv4 adresi', '185.98.142.21'],
            'AAAA' => ['IPv6 adresi', '2a0e:1c80:4:2a::21'],
            'CNAME' => ['Hedef alan adı', 'ornek.com.tr.'],
            'MX' => ['Posta sunucusu', 'mail.ornek.com.tr.'],
            'TXT' => ['Değer', 'v=spf1 a mx ~all'],
            'NS' => ['Ad sunucusu', 'ns1.ornek.com.tr.'],
            'SRV' => ['Ağırlık, port ve hedef', '5 5060 sip.ornek.com.tr.'],
            'CAA' => ['Değer', '0 issue "letsencrypt.org"'],
            default => ['Değer', ''],
        };
    }

    public static function ttlLabel(int $ttl): string
    {
        return match (true) {
            isset(self::TTLS[$ttl]) => self::TTLS[$ttl],
            $ttl % 86400 === 0 => ($ttl / 86400).' gün',
            $ttl % 3600 === 0 => ($ttl / 3600).' saat',
            $ttl % 60 === 0 => ($ttl / 60).' dakika',
            default => $ttl.' sn',
        };
    }

    /**
     * Turn what the user typed as a host name ("", "@", "www",
     * "www.ornek.com.tr.") into the stored relative name, or null if it is
     * not valid.
     */
    public static function normalizeName(string $name, string $zone): ?string
    {
        $name = rtrim(mb_strtolower(trim($name)), '.');
        if ($name === '' || $name === '@' || $name === $zone) {
            return '@';
        }
        if (str_ends_with($name, '.'.$zone)) {
            $name = substr($name, 0, -strlen('.'.$zone));
        }
        $labels = explode('.', $name);
        foreach ($labels as $i => $label) {
            if ($label === '*' && $i === 0) {
                continue;
            }
            if (! preg_match(self::LABEL, $label)) {
                return null;
            }
        }

        return strlen($name.'.'.$zone) <= 253 ? $name : null;
    }

    /**
     * Absolute display form of a relative name.
     */
    public static function fqdn(string $name, string $zone): string
    {
        return $name === '@' ? $zone : $name.'.'.$zone;
    }

    /**
     * Validate a value and return [stored content, error message]. Host
     * names are made absolute; a bare label ("mail") is taken as relative
     * to the zone.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function normalizeContent(string $type, string $value, string $zone): array
    {
        $value = trim($value);
        if ($value === '') {
            return [null, 'Bir değer girin.'];
        }

        switch ($type) {
            case 'A':
                return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                    ? [$value, null]
                    : [null, 'Geçerli bir IPv4 adresi girin, örneğin 185.98.142.21.'];
            case 'AAAA':
                $packed = filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? inet_pton($value) : false;

                return $packed !== false && ! str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")
                    ? [inet_ntop($packed), null]
                    : [null, 'Geçerli bir IPv6 adresi girin, örneğin 2a0e:1c80:4:2a::21.'];
            case 'CNAME':
            case 'NS':
            case 'MX':
                $host = self::hostname($value, $zone);

                return $host !== null || ($type === 'MX' && $value === '.')
                    ? [$host ?? '.', null]
                    : [null, 'Geçerli bir alan adı girin, örneğin '.self::valueField($type)[1]];
            case 'SRV':
                $parts = preg_split('/\s+/', $value);
                if (count($parts) !== 3 || ! self::isUint16($parts[0]) || ! self::isUint16($parts[1])) {
                    return [null, 'Ağırlık, port ve hedefi boşlukla ayırarak yazın, örneğin 5 5060 sip.ornek.com.tr.'];
                }
                $host = $parts[2] === '.' ? '.' : self::hostname($parts[2], $zone);

                return $host === null
                    ? [null, 'Hedef geçerli bir alan adı olmalı.']
                    : [(int) $parts[0].' '.(int) $parts[1].' '.$host, null];
            case 'TXT':
                return strlen($value) <= 4000
                    ? [$value, null]
                    : [null, 'TXT değeri en fazla 4000 karakter olabilir.'];
            case 'CAA':
                if (! preg_match('/^(\d{1,3})\s+(issue|issuewild|iodef)\s+"?([\x21\x23-\x5b\x5d-\x7e]+)"?$/i', $value, $m) || (int) $m[1] > 255) {
                    return [null, 'Bayrak, etiket ve değer yazın, örneğin 0 issue "letsencrypt.org".'];
                }

                return [(int) $m[1].' '.strtolower($m[2]).' "'.$m[3].'"', null];
        }

        return [null, 'Desteklenmeyen kayıt türü.'];
    }

    public static function isValidPriority(mixed $priority): bool
    {
        return is_numeric($priority) && self::isUint16((string) $priority);
    }

    /**
     * Absolute host name with a trailing dot, or null.
     */
    public static function hostname(string $host, string $zone): ?string
    {
        $host = rtrim(mb_strtolower(trim($host)), '.');
        if ($host !== '' && ! str_contains($host, '.')) {
            $host .= '.'.$zone;
        }
        $host = Hostnames::normalize($host);
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        foreach (explode('.', $host) as $label) {
            if (! preg_match(self::LABEL, $label)) {
                return null;
            }
        }

        return $host.'.';
    }

    private static function isUint16(string $v): bool
    {
        return ctype_digit($v) && (int) $v <= 65535;
    }
}
