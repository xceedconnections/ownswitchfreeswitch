<?php

declare(strict_types=1);

namespace Ownswitch\Http;

use Ownswitch\App;

final class Request
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     * @param array<string,mixed> $server
     * @param array<string,string> $headers lower-cased names
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $headers = [],
        public readonly string $basePath = '',
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);

        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        $base = rtrim(dirname($script), '/');
        if ($base === '.' || $base === '\\') {
            $base = '';
        }
        // When served with docroot = public/, base is ''. Under /Ownswitch/public it is that prefix.
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($server as $k => $v) {
            if (str_starts_with((string) $k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $k, 5)))] = (string) $v;
            }
        }
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        return new self($method, $path, $_GET, $_POST, $server, $headers, $base, $_FILES);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function wantsJson(): bool
    {
        return str_contains((string) $this->header('accept'), 'application/json')
            || str_contains((string) $this->header('content-type'), 'application/json');
    }

    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) App::config('security.trusted_proxies', []);
        if ($trusted !== [] && in_array($remote, $trusted, true)) {
            $xff = (string) ($this->headers['x-forwarded-for'] ?? '');
            $first = trim(explode(',', $xff)[0] ?? '');
            if ($first !== '' && filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return $remote;
    }

    public function attr(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttr(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }
}
