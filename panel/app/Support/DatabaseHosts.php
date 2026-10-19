<?php

namespace App\Support;

/**
 * Remote hosts a database user may connect from, as the agent accepts
 * them: % (anywhere), an IPv4 or IPv6 address, an IPv4 network such as
 * 88.247.120.0/28 or an IPv4 prefix such as 10.8.0.%.
 */
final class DatabaseHosts
{
    public const ANY = '%';

    /** At most this many hosts per user. */
    public const MAX = 20;

    /**
     * Hosts from free text: one per line, or separated by commas or
     * spaces.
     *
     * @return list<string>
     */
    public static function parse(string $text): array
    {
        $hosts = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique($hosts));
    }

    public static function valid(string $host): bool
    {
        if ($host === self::ANY) {
            return true;
        }
        if (str_ends_with($host, '.%')) {
            $parts = explode('.', substr($host, 0, -2));
            if (count($parts) > 3) {
                return false;
            }
            foreach ($parts as $part) {
                if (! preg_match('/^(0|[1-9][0-9]{0,2})$/', $part) || (int) $part > 255) {
                    return false;
                }
            }

            return true;
        }
        if (str_contains($host, '/')) {
            [$address, $bits] = explode('/', $host, 2);
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! preg_match('/^(0|[1-9][0-9]?)$/', $bits) || (int) $bits > 32) {
                return false;
            }
            // The address must be the network's first one.
            $mask = (int) $bits === 0 ? 0 : (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;

            return (ip2long($address) & $mask) === ip2long($address);
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false && ! str_contains($host, '%');
    }
}
