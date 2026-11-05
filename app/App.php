<?php

declare(strict_types=1);

namespace Ownswitch;

use Ownswitch\Database\Connection;
use Ownswitch\Http\Request;
use Ownswitch\Middleware\AuthMiddleware;
use Ownswitch\Middleware\CsrfMiddleware;
use Ownswitch\Middleware\MiddlewareInterface;
use Ownswitch\Middleware\RoleMiddleware;
use Ownswitch\Middleware\TenantMiddleware;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Services\Auth\AuthService;
use Ownswitch\Services\Auth\SessionService;
use Ownswitch\Support\Env;

/** Tiny service registry (no DI container needed). */
final class App
{
    private static string $basePath = '';
    /** @var array<string,mixed> */
    private static array $config = [];
    private static ?\PDO $pdo = null;
    private static ?SessionService $session = null;
    private static ?Request $request = null;

    public static function boot(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '\\/');
        $envFile = Env::get('OWNSWITCH_ENV_FILE') ?: self::$basePath . '/config/.env';
        Env::load((string) $envFile);
        foreach (['app', 'database', 'security'] as $name) {
            self::$config[$name] = require self::$basePath . "/config/{$name}.php";
        }
        date_default_timezone_set((string) self::config('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');
    }

    public static function basePath(string $rel = ''): string
    {
        return self::$basePath . ($rel !== '' ? '/' . ltrim(str_replace('\\', '/', $rel), '/') : '');
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $cur = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cur) || !array_key_exists($part, $cur)) {
                return $default;
            }
            $cur = $cur[$part];
        }
        return $cur;
    }

    public static function setConfig(string $name, array $value): void
    {
        self::$config[$name] = $value;
    }

    public static function db(): \PDO
    {
        return self::$pdo ??= Connection::make((array) self::config('database.default'));
    }

    public static function setDb(?\PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function session(): SessionService
    {
        return self::$session ??= new SessionService();
    }

    public static function setSession(?SessionService $s): void
    {
        self::$session = $s;
    }

    public static function request(): ?Request
    {
        return self::$request;
    }

    public static function setRequest(?Request $r): void
    {
        self::$request = $r;
    }

    public static function auth(): AuthService
    {
        return new AuthService(new UserRepository(self::db()), self::session(), self::db());
    }

    /** Resolve a middleware alias: auth | csrf | tenant | role:a,b */
    public static function middleware(string $spec): MiddlewareInterface
    {
        [$name, $arg] = array_pad(explode(':', $spec, 2), 2, '');
        return match ($name) {
            'auth'   => new AuthMiddleware(self::session(), new UserRepository(self::db())),
            'csrf'   => new CsrfMiddleware(self::session()),
            'tenant' => new TenantMiddleware(),
            'role'   => new RoleMiddleware(array_values(array_filter(explode(',', $arg)))),
            default  => throw new \InvalidArgumentException("Unknown middleware {$spec}"),
        };
    }
}
