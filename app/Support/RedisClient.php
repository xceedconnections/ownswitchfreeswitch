<?php

declare(strict_types=1);

namespace Ownswitch\Support;

/**
 * Minimal Redis RESP client (no extension). Used for O(1) DNC set membership.
 */
final class RedisClient
{
    /** @var resource|null */
    private $fp = null;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private string $password = '',
        private int $db = 0,
        private float $timeout = 1.5,
    ) {
    }

    public static function fromConfig(): ?self
    {
        $cfg = (array) \Ownswitch\App::config('app.integrations.redis', []);
        if (empty($cfg['enabled'])) {
            return null;
        }
        return new self(
            (string) ($cfg['host'] ?? '127.0.0.1'),
            (int) ($cfg['port'] ?? 6379),
            (string) ($cfg['password'] ?? ''),
            (int) ($cfg['db'] ?? 1),
        );
    }

    public function ping(): bool
    {
        try {
            return $this->command(['PING']) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param list<string> $members */
    public function sadd(string $key, array $members): int
    {
        if ($members === []) {
            return 0;
        }
        $args = ['SADD', $key, ...$members];
        return (int) $this->command($args);
    }

    /** @param list<string> $members */
    public function srem(string $key, array $members): int
    {
        if ($members === []) {
            return 0;
        }
        return (int) $this->command(['SREM', $key, ...$members]);
    }

    public function del(string $key): int
    {
        return (int) $this->command(['DEL', $key]);
    }

    public function sismember(string $key, string $member): bool
    {
        return (int) $this->command(['SISMEMBER', $key, $member]) === 1;
    }

    /** Replace set contents in chunks (DEL then SADD). */
    public function replaceSet(string $key, array $members, int $chunk = 500): void
    {
        $this->del($key);
        $chunk = max(50, $chunk);
        foreach (array_chunk(array_values($members), $chunk) as $part) {
            $this->sadd($key, $part);
        }
    }

    public function close(): void
    {
        if (is_resource($this->fp)) {
            fclose($this->fp);
        }
        $this->fp = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @param list<string> $args */
    private function command(array $args): mixed
    {
        $this->connect();
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $s = (string) $a;
            $payload .= '$' . strlen($s) . "\r\n" . $s . "\r\n";
        }
        $written = fwrite($this->fp, $payload);
        if ($written === false) {
            $this->close();
            throw new \RuntimeException('Redis write failed');
        }
        return $this->readReply();
    }

    private function connect(): void
    {
        if (is_resource($this->fp)) {
            return;
        }
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            'tcp://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout
        );
        if ($fp === false) {
            throw new \RuntimeException('Redis connect failed: ' . $errstr);
        }
        stream_set_timeout($fp, (int) ceil($this->timeout));
        $this->fp = $fp;
        if ($this->password !== '') {
            $this->command(['AUTH', $this->password]);
        }
        if ($this->db > 0) {
            $this->command(['SELECT', (string) $this->db]);
        }
    }

    private function readReply(): mixed
    {
        $line = fgets($this->fp);
        if ($line === false) {
            $this->close();
            throw new \RuntimeException('Redis read failed');
        }
        $type = $line[0] ?? '';
        $payload = substr($line, 1, -2); // strip \r\n
        return match ($type) {
            '+' => $payload,
            '-' => throw new \RuntimeException('Redis error: ' . $payload),
            ':' => (int) $payload,
            '$' => $this->readBulk((int) $payload),
            '*' => $this->readArray((int) $payload),
            default => throw new \RuntimeException('Redis protocol error'),
        };
    }

    private function readBulk(int $len): ?string
    {
        if ($len < 0) {
            return null;
        }
        $data = '';
        $need = $len + 2;
        while ($need > 0) {
            $chunk = fread($this->fp, $need);
            if ($chunk === false || $chunk === '') {
                $this->close();
                throw new \RuntimeException('Redis bulk read failed');
            }
            $data .= $chunk;
            $need -= strlen($chunk);
        }
        return substr($data, 0, $len);
    }

    /** @return list<mixed> */
    private function readArray(int $n): array
    {
        if ($n < 0) {
            return [];
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->readReply();
        }
        return $out;
    }
}
