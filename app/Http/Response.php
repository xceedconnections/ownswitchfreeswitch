<?php

declare(strict_types=1);

namespace Ownswitch\Http;

use Ownswitch\App;

final class Response
{
    /** @var array<string,string> */
    private array $headers;
    /** @var (callable():void)|null streaming body */
    private $stream = null;

    /** @param array<string,string> $headers */
    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        $this->headers = $headers + ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param callable():void $writer */
    public static function stream(callable $writer, string $contentType, array $headers = []): self
    {
        $r = new self('', 200, ['Content-Type' => $contentType] + $headers);
        $r->stream = $writer;
        return $r;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withSecurityHeaders(): self
    {
        $h = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'DENY',
            'Referrer-Policy'        => 'same-origin',
            'Permissions-Policy'     => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => (string) App::config('security.csp', "default-src 'self'"),
            'Cache-Control'          => 'no-store',
        ];
        foreach ($h as $k => $v) {
            $this->headers[$k] ??= $v;
        }
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        // Prefer Content-Length over chunked Transfer-Encoding — mod_xml_curl has
        // historically failed to parse otherwise-valid directory/dialplan documents.
        if ($this->stream === null && !isset($this->headers['Content-Length'])) {
            $this->headers['Content-Length'] = (string) strlen($this->body);
        }
        foreach ($this->headers as $k => $v) {
            header($k . ': ' . str_replace(["\r", "\n"], '', $v));
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
