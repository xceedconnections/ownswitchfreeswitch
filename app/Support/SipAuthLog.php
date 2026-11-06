<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * One syslog line per rejected SIP auth attempt.
 * fail2ban jail opensips-auth matches "REGISTER|INVITE auth failed ... from=<ip>".
 */
final class SipAuthLog
{
    public static function failed(string $kind, string $user, string $ip): void
    {
        $kind = strtoupper(trim($kind)) === 'INVITE' ? 'INVITE' : 'REGISTER';
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return;
        }
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return;
        }
        $user = preg_replace('/[^A-Za-z0-9_.@+-]/', '', $user) ?? '';
        if ($user === '') {
            $user = 'unknown';
        }
        $user = substr($user, 0, 80);
        if (!function_exists('openlog') || !function_exists('syslog')) {
            return;
        }
        openlog('ownswitch-sip', LOG_PID | LOG_ODELAY, LOG_AUTH);
        syslog(LOG_WARNING, $kind . ' auth failed user=' . $user . ' from=' . $ip);
        closelog();
    }
}
