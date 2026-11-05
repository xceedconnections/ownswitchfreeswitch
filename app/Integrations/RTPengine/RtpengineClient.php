<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\RTPengine;

/**
 * RTPengine "ng" control protocol over UDP: "<cookie> <bencoded dict>" -> "<cookie> <bencoded dict>".
 * Used for health ping and (later) offer/answer/delete helpers; OpenSIPS' rtpengine module does live call control.
 */
final class RtpengineClient
{
    public function __construct(private string $host, private int $port = 22222, private float $timeout = 2.0)
    {
    }

    public function configured(): bool
    {
        return $this->host !== '';
    }

    /** @param array<string,mixed> $params */
    public function command(string $command, array $params = []): array
    {
        if (!$this->configured()) {
            throw new \RuntimeException('RTPengine host not configured');
        }
        $cookie = bin2hex(random_bytes(6));
        $msg = $cookie . ' ' . self::bencode(['command' => $command] + $params);
        $sock = @stream_socket_client("udp://{$this->host}:{$this->port}", $errno, $err, $this->timeout);
        if (!$sock) {
            throw new \RuntimeException("RTPengine connect failed: {$err}");
        }
        stream_set_timeout($sock, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1e6));
        fwrite($sock, $msg);
        $resp = fread($sock, 65535);
        fclose($sock);
        if ($resp === false || $resp === '') {
            throw new \RuntimeException('RTPengine timeout');
        }
        if (!str_starts_with($resp, $cookie . ' ')) {
            throw new \RuntimeException('RTPengine cookie mismatch');
        }
        $pos = 0;
        $data = self::bdecode(substr($resp, strlen($cookie) + 1), $pos);
        return is_array($data) ? $data : [];
    }

    public function ping(): bool
    {
        return ($this->command('ping')['result'] ?? '') === 'pong';
    }

    public static function bencode(mixed $v): string
    {
        if (is_int($v)) {
            return 'i' . $v . 'e';
        }
        if (is_bool($v)) {
            return $v ? '3:yes' : '2:no';
        }
        if (is_string($v)) {
            return strlen($v) . ':' . $v;
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return 'l' . implode('', array_map([self::class, 'bencode'], $v)) . 'e';
            }
            ksort($v, SORT_STRING);
            $out = 'd';
            foreach ($v as $k => $val) {
                $out .= self::bencode((string) $k) . self::bencode($val);
            }
            return $out . 'e';
        }
        throw new \InvalidArgumentException('Unsupported bencode type');
    }

    public static function bdecode(string $s, int &$pos = 0): mixed
    {
        $c = $s[$pos] ?? '';
        if ($c === 'i') {
            $end = strpos($s, 'e', $pos);
            if ($end === false) {
                throw new \RuntimeException('Bad bencode integer');
            }
            $n = (int) substr($s, $pos + 1, $end - $pos - 1);
            $pos = $end + 1;
            return $n;
        }
        if ($c === 'l' || $c === 'd') {
            $pos++;
            $items = [];
            while (($s[$pos] ?? 'e') !== 'e') {
                if ($c === 'l') {
                    $items[] = self::bdecode($s, $pos);
                } else {
                    $k = self::bdecode($s, $pos);
                    $items[(string) $k] = self::bdecode($s, $pos);
                }
            }
            $pos++;
            return $items;
        }
        if (ctype_digit($c)) {
            $colon = strpos($s, ':', $pos);
            if ($colon === false) {
                throw new \RuntimeException('Bad bencode string');
            }
            $len = (int) substr($s, $pos, $colon - $pos);
            $val = substr($s, $colon + 1, $len);
            $pos = $colon + 1 + $len;
            return $val;
        }
        throw new \RuntimeException('Bad bencode data');
    }
}
