<?php

namespace App\Services\Mail;

/**
 * Mailbox passwords: the panel hashes them for Dovecot, so the agent and
 * the database never hold a password.
 */
class MailPassword
{
    public const MIN_LENGTH = 8;

    private const SALT = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /**
     * A Dovecot SHA512-CRYPT hash: {SHA512-CRYPT}$6$<salt>$<hash>.
     */
    public static function hash(string $password): string
    {
        $salt = '';
        for ($i = 0; $i < 16; $i++) {
            $salt .= self::SALT[random_int(0, strlen(self::SALT) - 1)];
        }

        return '{SHA512-CRYPT}'.crypt($password, '$6$'.$salt.'$');
    }

    public static function verify(string $password, string $hash): bool
    {
        $crypt = substr($hash, strlen('{SHA512-CRYPT}'));

        return str_starts_with($hash, '{SHA512-CRYPT}') && hash_equals($crypt, crypt($password, $crypt));
    }

    /**
     * A password that is easy to read out and type: three groups of four
     * letters and digits, without look-alikes (0/O, 1/l/I).
     */
    public static function generate(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $groups = [];
            for ($g = 0; $g < 3; $g++) {
                $part = '';
                for ($i = 0; $i < 4; $i++) {
                    $part .= $chars[random_int(0, strlen($chars) - 1)];
                }
                $groups[] = $part;
            }
            $password = implode('-', $groups);
        } while (self::weakness($password) !== null);

        return $password;
    }

    /**
     * Why a password is too weak, or null when it is fine.
     */
    public static function weakness(string $password, string $address = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Parola en az '.self::MIN_LENGTH.' karakter olmalı.';
        }
        if (! preg_match('/\pL/u', $password) || ! preg_match('/\d/', $password)) {
            return 'Parolada hem harf hem rakam olmalı.';
        }
        $local = strstr($address, '@', true) ?: $address;
        if ($local !== '' && mb_strlen($local) >= 3 && str_contains(mb_strtolower($password), mb_strtolower($local))) {
            return 'Parola e-posta adresini içermemeli.';
        }

        return null;
    }
}
