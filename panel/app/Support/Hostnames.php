<?php

namespace App\Support;

/**
 * Host name and account name rules shared with servik-agent
 * (agent/internal/site/validate.go). The agent has the final say; these
 * give the form an early, friendly error.
 */
class Hostnames
{
    public const SYSTEM_USER_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /** Account names the agent refuses. */
    public const RESERVED_USERS = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news', 'uucp', 'proxy',
        'www-data', 'backup', 'list', 'irc', 'gnats', 'nobody', 'syslog', 'ubuntu', 'admin',
        'administrator', 'nginx', 'apache', 'httpd', 'postgres', 'mysql', 'redis', 'servik',
        'servik-agent', 'ftp', 'sshd', 'dovecot', 'postfix', 'pdns', 'named', 'bind', 'clamav',
    ];

    /**
     * Lowercase, trim and convert an internationalised name (kahveatölyesi.com)
     * to its ASCII form (xn--kahveatlyesi-...). Returns the input lowercased
     * when conversion is impossible, so validation reports it.
     */
    public static function normalize(string $domain): string
    {
        $domain = mb_strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim((string) $domain, './');

        if (preg_match('/[^\x20-\x7e]/', $domain) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                return $ascii;
            }
        }

        return $domain;
    }

    public static function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }
        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }
        foreach ($labels as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return (bool) preg_match('/^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', end($labels));
    }

    /**
     * Suggest an account name from a domain: "www.kahve-atolyesi.com.tr"
     * becomes "kahveatolyesi".
     */
    public static function suggestUser(string $domain): string
    {
        $first = explode('.', preg_replace('/^www\./', '', $domain))[0] ?? '';
        $name = substr((string) preg_replace('/[^a-z0-9]/', '', $first), 0, 16);
        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'site'.$name;
        }
        if (in_array($name, self::RESERVED_USERS, true)) {
            $name .= 'site';
        }

        return substr($name, 0, 32);
    }
}
