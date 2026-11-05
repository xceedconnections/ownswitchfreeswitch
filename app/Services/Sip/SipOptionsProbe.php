<?php

declare(strict_types=1);

namespace Ownswitch\Services\Sip;

/**
 * VoIP signalling latency via SIP OPTIONS RTT (Magnus-style Live ms).
 * No ICMP — ping latency is not SIP latency.
 */
final class SipOptionsProbe
{
    /** @var array<string, array{ms:?int, at:float}> */
    private static array $cache = [];

    /**
     * @return int|null Round-trip milliseconds from a SIP reply, or null if no SIP response
     */
    public static function rttMs(string $host, int $port = 5060, float $timeoutSec = 1.5, ?string $user = null): ?int
    {
        $host = trim($host);
        $port = $port > 0 && $port <= 65535 ? $port : 5060;
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $user = $user !== null ? trim($user) : '';
        $key = strtolower($host) . ':' . $port . ':' . strtolower($user);
        $now = microtime(true);
        if (isset(self::$cache[$key]) && ($now - self::$cache[$key]['at']) < 15.0) {
            return self::$cache[$key]['ms'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ip = $host;
        } else {
            $resolved = @gethostbyname($host);
            if (!is_string($resolved) || $resolved === '' || $resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
                self::$cache[$key] = ['ms' => null, 'at' => $now];
                return null;
            }
            $ip = $resolved;
        }

        // Prefer AOR username in R-URI (Asterisk/softphones often ignore sip:ping@…)
        $ms = self::sipOptionsRtt($ip, $host, $port, $timeoutSec, $user !== '' ? $user : null);
        if ($ms === null && $user !== '') {
            $ms = self::sipOptionsRtt($ip, $host, $port, $timeoutSec, null);
        }
        self::$cache[$key] = ['ms' => $ms, 'at' => $now];
        return $ms;
    }

    private static function sipOptionsRtt(string $ip, string $host, int $port, float $timeoutSec, ?string $user): ?int
    {
        $target = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;
        $sock = @stream_socket_client(
            'udp://' . $target . ':' . $port,
            $errno,
            $errstr,
            $timeoutSec
        );
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, (int) floor($timeoutSec), (int) (($timeoutSec - floor($timeoutSec)) * 1_000_000));

        $branch = 'z9hG4bK' . bin2hex(random_bytes(6));
        $callId = bin2hex(random_bytes(8)) . '@ownswitch';
        $tag = bin2hex(random_bytes(4));
        // Via/Contact must be a routable IP so peers that validate Via still reply
        $fromHost = $ip;
        $ruriUser = ($user !== null && $user !== '' && preg_match('/^[A-Za-z0-9._\-+=]+$/', $user))
            ? $user
            : 'options';
        $msg = "OPTIONS sip:{$ruriUser}@{$host}:{$port} SIP/2.0\r\n"
            . "Via: SIP/2.0/UDP {$fromHost}:5060;branch={$branch};rport\r\n"
            . "Max-Forwards: 70\r\n"
            . "From: <sip:options@{$fromHost}>;tag={$tag}\r\n"
            . "To: <sip:{$ruriUser}@{$host}:{$port}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 OPTIONS\r\n"
            . "Contact: <sip:options@{$fromHost}:5060>\r\n"
            . "User-Agent: Ownswitch\r\n"
            . "Accept: application/sdp\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $t0 = hrtime(true);
        @fwrite($sock, $msg);
        $resp = @fread($sock, 4096);
        $elapsedMs = (int) max(1, (int) round((hrtime(true) - $t0) / 1_000_000));
        fclose($sock);

        // Any SIP reply counts (200/404/405/480/501) — that RTT is VoIP signalling latency
        if (is_string($resp) && $resp !== '' && str_starts_with($resp, 'SIP/2.0')) {
            return min(9999, $elapsedMs);
        }
        return null;
    }

    /**
     * Parse host + port (+ optional user) from a SIP Contact / received URI.
     *
     * @return array{host:string,port:int,user:string}|null
     */
    public static function endpointFromUri(string $uri): ?array
    {
        $uri = trim($uri);
        if ($uri === '') {
            return null;
        }
        if (preg_match('#(?:sip:)?(?:([^@;>\s]+)@)?(\[[0-9a-fA-F:]+\]|[^:;>\s]+)(?::(\d+))?#', $uri, $m)) {
            $host = $m[2];
            $port = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 5060;
            $user = isset($m[1]) ? trim((string) $m[1]) : '';
            return ['host' => $host, 'port' => $port, 'user' => $user];
        }
        return null;
    }

    public static function format(?int $ms, bool $online = true): string
    {
        if (!$online) {
            return '—';
        }
        if ($ms === null) {
            return 'n/a';
        }
        return $ms . ' ms';
    }
}
