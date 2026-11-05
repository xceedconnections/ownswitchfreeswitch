<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Parse / store one or more SIP peer IPs on sip_accounts.sip_host.
 * Dynamic mode → "dynamic". IP mode → comma-separated IPv4/IPv6 (no spaces).
 */
final class SipHosts
{
    /** @return list<string> */
    public static function parse(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '' || strtolower($raw) === 'dynamic') {
            return [];
        }
        $out = [];
        foreach (preg_split('/[\s,;|]+/', $raw) ?: [] as $p) {
            $p = trim($p);
            if ($p === '' || strtolower($p) === 'dynamic') {
                continue;
            }
            // Strip optional /mask if pasted as CIDR; we authorize /32 (or /128) hosts.
            if (str_contains($p, '/')) {
                $p = explode('/', $p, 2)[0];
            }
            if (filter_var($p, FILTER_VALIDATE_IP)) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }

    /** Store form: "dynamic" or "1.2.3.4,5.6.7.8". */
    public static function normalize(?string $raw): string
    {
        $ips = self::parse($raw);
        return $ips === [] ? 'dynamic' : implode(',', $ips);
    }

    public static function primary(string $stored): string
    {
        $ips = self::parse($stored);
        return $ips[0] ?? 'dynamic';
    }

    public static function isDynamic(string $stored): bool
    {
        return self::parse($stored) === [];
    }

    public static function matches(string $stored, string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        return in_array($ip, self::parse($stored), true);
    }

    /** FreeSWITCH directory cidr param value. */
    public static function cidrParam(string $stored): string
    {
        $parts = [];
        foreach (self::parse($stored) as $ip) {
            $parts[] = $ip . (str_contains($ip, ':') ? '/128' : '/32');
        }
        return implode(',', $parts);
    }

    /** Edit form: one IP per line. */
    public static function forForm(string $stored): string
    {
        $ips = self::parse($stored);
        return $ips === [] ? 'dynamic' : implode("\n", $ips);
    }
}
