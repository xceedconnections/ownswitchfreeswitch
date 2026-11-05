<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Country / city / ISP for an IP.
 * Tries curl CLI first (allow_url_fopen is often off under php-fpm), then file_get_contents.
 * Providers: ip-api.com → ipwho.is (no key).
 */
final class GeoIp
{
    /** @return array{country:string,countryCode:string,city:string,isp:string} */
    public static function lookup(string $ip): array
    {
        $empty = ['country' => 'Unknown', 'countryCode' => '', 'city' => '', 'isp' => ''];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $empty;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['country' => 'Private / local', 'countryCode' => '', 'city' => '', 'isp' => ''];
        }

        $cacheKey = 'os_geo_' . $ip;
        if (function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $ok);
            if ($ok && is_array($hit) && ($hit['country'] ?? '') !== '' && ($hit['country'] ?? '') !== 'Unknown') {
                return $hit + $empty;
            }
        }
        $file = sys_get_temp_dir() . '/ownswitch_geo_' . md5($ip) . '.json';
        if (is_file($file) && (time() - (int) filemtime($file)) < 86400) {
            $j = json_decode((string) file_get_contents($file), true);
            if (is_array($j) && ($j['country'] ?? '') !== '' && ($j['country'] ?? '') !== 'Unknown') {
                return $j + $empty;
            }
        }

        $out = self::fromIpApi($ip) ?? self::fromIpWho($ip) ?? $empty;
        if (($out['country'] ?? '') !== '' && $out['country'] !== 'Unknown') {
            @file_put_contents($file, json_encode($out));
            if (function_exists('apcu_store')) {
                apcu_store($cacheKey, $out, 86400);
            }
        }
        return $out;
    }

    /** @return array{country:string,countryCode:string,city:string,isp:string}|null */
    private static function fromIpApi(string $ip): ?array
    {
        $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,country,countryCode,city,isp';
        $raw = self::httpGet($url);
        if ($raw === null) {
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || ($j['status'] ?? '') !== 'success') {
            return null;
        }
        $country = trim((string) ($j['country'] ?? ''));
        if ($country === '') {
            return null;
        }
        return [
            'country' => $country,
            'countryCode' => (string) ($j['countryCode'] ?? ''),
            'city' => (string) ($j['city'] ?? ''),
            'isp' => (string) ($j['isp'] ?? ''),
        ];
    }

    /** @return array{country:string,countryCode:string,city:string,isp:string}|null */
    private static function fromIpWho(string $ip): ?array
    {
        $url = 'https://ipwho.is/' . rawurlencode($ip);
        $raw = self::httpGet($url);
        if ($raw === null) {
            return null;
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || empty($j['success'])) {
            return null;
        }
        $country = trim((string) ($j['country'] ?? ''));
        if ($country === '') {
            return null;
        }
        return [
            'country' => $country,
            'countryCode' => (string) ($j['country_code'] ?? ''),
            'city' => (string) ($j['city'] ?? ''),
            'isp' => (string) (($j['connection']['isp'] ?? null) ?: ($j['isp'] ?? '')),
        ];
    }

    private static function httpGet(string $url): ?string
    {
        // Prefer curl binary — works when allow_url_fopen=Off
        $curl = self::findCurl();
        if ($curl !== null) {
            $cmd = escapeshellarg($curl) . ' -fsSL --max-time 3 '
                . escapeshellarg($url) . ' 2>/dev/null';
            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                $cmd = escapeshellarg($curl) . ' -fsSL --max-time 3 ' . escapeshellarg($url);
            }
            $out = [];
            $code = 1;
            @exec($cmd, $out, $code);
            if ($code === 0 && $out !== []) {
                return implode("\n", $out);
            }
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'ignore_errors' => true, 'header' => "User-Agent: Ownswitch/1.0\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        return ($raw === false || $raw === '') ? null : $raw;
    }

    private static function findCurl(): ?string
    {
        foreach (['/usr/bin/curl', '/bin/curl', 'curl'] as $c) {
            if ($c === 'curl') {
                return 'curl';
            }
            if (is_executable($c)) {
                return $c;
            }
        }
        return null;
    }
}
