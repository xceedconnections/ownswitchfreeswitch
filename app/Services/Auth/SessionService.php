<?php

declare(strict_types=1);

namespace Ownswitch\Services\Auth;

use Ownswitch\App;

/**
 * Session wrapper. Pass an array to the constructor for tests (no native session).
 * Cookies: HttpOnly, SameSite, Secure (when configured). ID regenerated on login.
 */
final class SessionService
{
    /** @var array<string,mixed> */
    private array $data = [];
    private bool $native;
    private bool $started = false;

    /** @param array<string,mixed>|null $backing */
    public function __construct(?array $backing = null)
    {
        $this->native = $backing === null;
        if (!$this->native) {
            $this->data = $backing;
            $this->started = true;
        }
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $cfg = (array) App::config('security.session', []);
        if (session_status() === PHP_SESSION_NONE) {
            session_name((string) ($cfg['name'] ?? 'OWNSWITCHSESS'));
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => (bool) ($cfg['secure'] ?? false),
                'httponly' => true,
                'samesite' => (string) ($cfg['samesite'] ?? 'Lax'),
            ]);
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_start();
        }
        $this->started = true;
        $this->data = &$_SESSION;
        $life = (int) ($cfg['lifetime'] ?? 7200);
        $last = (int) ($this->data['_last'] ?? time());
        if (time() - $last > $life) {
            $this->destroy();
            $this->start();
            return;
        }
        $this->data['_last'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $v = $this->data[$key] ?? $default;
        unset($this->data[$key]);
        return $v;
    }

    public function flash(string $type, string $message): void
    {
        $this->data['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function pullFlash(): array
    {
        return (array) $this->pull('_flash', []);
    }

    public function csrfToken(): string
    {
        $this->start();
        if (empty($this->data['_csrf']) || !is_string($this->data['_csrf'])) {
            $this->data['_csrf'] = bin2hex(random_bytes(32));
        }
        return $this->data['_csrf'];
    }

    public function validateCsrf(?string $token): bool
    {
        $this->start();
        $expected = $this->data['_csrf'] ?? null;
        return is_string($expected) && is_string($token) && $token !== '' && hash_equals($expected, $token);
    }

    public function userId(): ?int
    {
        $id = $this->data['user_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public function login(int $userId): void
    {
        $this->start();
        if ($this->native) {
            session_regenerate_id(true);   // prevent session fixation
        }
        $this->data['user_id'] = $userId;
        $this->data['_csrf'] = bin2hex(random_bytes(32));
    }

    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function destroy(): void
    {
        if ($this->native) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
                if (ini_get('session.use_cookies') && !headers_sent()) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax']);
                }
                session_destroy();
            }
            $this->started = false;
            $this->data = [];
        } else {
            $this->data = [];
        }
    }
}
