<?php

namespace App\Services\Security;

/**
 * Writes failed panel logins to the system journal, where Fail2ban's
 * serfex-panel jail counts them: ten failures from an address within 15
 * minutes close the panel's port to it for an hour.
 */
class LoginLog
{
    /** The journal's identifier the jail matches (SYSLOG_IDENTIFIER). */
    public const IDENT = 'serfex-panel';

    public static function failed(?string $ip, string $email, string $what = 'password'): void
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false || app()->runningUnitTests()) {
            return;
        }
        $email = preg_replace('/[^\w.@+-]/u', '?', mb_substr($email, 0, 100));
        if (openlog(self::IDENT, LOG_PID, LOG_AUTH)) {
            syslog(LOG_WARNING, "Failed panel login from {$ip} for {$email} ({$what})");
            closelog();
        }
    }
}
