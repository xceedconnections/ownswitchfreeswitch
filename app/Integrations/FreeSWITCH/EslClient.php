<?php

declare(strict_types=1);

namespace Ownswitch\Integrations\FreeSWITCH;

/** Minimal FreeSWITCH Event Socket (inbound mode, default port 8021). Optional component. */
final class EslClient
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(private string $host, private int $port = 8021, private string $password = '', private float $timeout = 2.0)
    {
        $this->host = trim($host);
        $this->password = trim($password);
    }

    public function configured(): bool
    {
        return $this->host !== '';
    }

    public function connect(): void
    {
        if (!$this->configured()) {
            throw new \RuntimeException('FreeSWITCH host not configured');
        }
        $this->sock = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $err, $this->timeout);
        if (!$this->sock) {
            throw new \RuntimeException("ESL connect failed: {$err}");
        }
        stream_set_timeout($this->sock, (int) $this->timeout);
        $hello = $this->readFrame();
        if (($hello['Content-Type'] ?? '') !== 'auth/request') {
            throw new \RuntimeException('Unexpected ESL greeting');
        }
        $this->write('auth ' . $this->password);
        $reply = $this->readFrame();
        $text = trim((string) ($reply['Reply-Text'] ?? $reply['_body'] ?? ''));
        if (!str_starts_with($text, '+OK')) {
            throw new \RuntimeException(
                'ESL authentication failed'
                . ($text !== '' ? " ({$text})" : '')
                . '. Sync password: sudo bash /opt/ownswitch-src/deploy/fix-esl-auth.sh'
            );
        }
    }

    /** Run an API command (e.g. "status") and return its text body. */
    public function api(string $command): string
    {
        if (preg_match('/[\r\n]/', $command)) {
            throw new \InvalidArgumentException('Newlines are not allowed in ESL commands');
        }
        if ($this->sock === null) {
            $this->connect();
        }
        $this->write('api ' . $command);
        $frame = $this->readFrame();
        return (string) ($frame['_body'] ?? '');
    }

    public function status(): string
    {
        return trim($this->api('status'));
    }

    /** Send BYE both ways. $uuid is a FreeSWITCH channel UUID. */
    public function uuidKill(string $uuid): string
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            throw new \InvalidArgumentException('Not a FreeSWITCH channel UUID');
        }
        return trim($this->api('uuid_kill ' . $uuid));
    }

    /**
     * Live channels from `show channels as json`.
     *
     * @return list<array<string,mixed>>
     */
    public function channels(): array
    {
        $raw = $this->api('show channels as json');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $rows = $decoded['rows'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, "exit\n\n");
            fclose($this->sock);
            $this->sock = null;
        }
    }

    private function write(string $line): void
    {
        fwrite($this->sock, $line . "\n\n");
    }

    /** @return array<string,string> headers plus _body */
    private function readFrame(): array
    {
        $headers = [];
        while (($line = fgets($this->sock)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($headers !== []) {
                    break;
                }
                continue;
            }
            [$k, $v] = array_pad(explode(':', $line, 2), 2, '');
            $headers[trim($k)] = trim(urldecode(trim($v)));
        }
        if ($headers === []) {
            throw new \RuntimeException('ESL read timeout');
        }
        $len = (int) ($headers['Content-Length'] ?? 0);
        if ($len > 0) {
            $body = '';
            while (strlen($body) < $len && ($chunk = fread($this->sock, $len - strlen($body))) !== false && $chunk !== '') {
                $body .= $chunk;
            }
            $headers['_body'] = $body;
        }
        return $headers;
    }
}
