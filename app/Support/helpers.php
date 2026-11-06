<?php

declare(strict_types=1);

use Ownswitch\App;

if (!function_exists('e')) {
    /** HTML-escape for output. ALL view output of dynamic data must go through this. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('base_url')) {
    function base_url(): string
    {
        return App::request()?->basePath ?? '';
    }
}

if (!function_exists('url')) {
    function url(string $path = '/'): string
    {
        return base_url() . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel = 'assets/' . ltrim($path, '/');
        $file = OWNSWITCH_ROOT . '/public/' . $rel;
        $v = is_file($file) ? (string) filemtime($file) : (string) time();
        return base_url() . '/' . $rel . '?v=' . rawurlencode($v);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return App::session()->csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="' . e(App::config('security.csrf.field', '_csrf')) . '" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    /** Previously submitted form value (flash-persisted on validation failure). */
    function old(string $key, mixed $default = ''): mixed
    {
        $old = App::session()->get('_old', []);
        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('current_user')) {
    function current_user(): ?array
    {
        return App::request()?->attr('user');
    }
}

if (!function_exists('has_role')) {
    function has_role(string ...$roles): bool
    {
        $u = current_user();
        return $u !== null && in_array($u['role'], $roles, true);
    }
}

if (!function_exists('fmt_money')) {
    /** Display formatting only (string-based; never used for settlement). */
    function fmt_money(?string $v, int $scale = 4): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        return bcadd($v, '0', $scale);
    }
}

if (!function_exists('fmt_duration')) {
    function fmt_duration(int|string|null $sec): string
    {
        $sec = (int) $sec;
        return sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60);
    }
}

if (!function_exists('nav_active')) {
    function nav_active(string $prefix): string
    {
        $path = App::request()?->path ?? '/';
        if ($prefix === '/') {
            return $path === '/' ? ' active' : '';
        }
        // Exact match, or child path — but avoid /routing lighting up for /routing/simulate
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            // Prefer more specific sibling: /routing should not match /routing/simulate when that item exists separately
            if ($prefix === '/routing' && str_starts_with($path, '/routing/simulate')) {
                return '';
            }
            return ' active';
        }
        return '';
    }
}

if (!function_exists('user_timezone')) {
    function user_timezone(): string
    {
        return \Ownswitch\Support\Timezone::fromUser(current_user());
    }
}

if (!function_exists('fmt_local')) {
    /** Format a UTC datetime for the current user's timezone. */
    function fmt_local(?string $utc, string $format = 'Y-m-d H:i:s'): string
    {
        return \Ownswitch\Support\Timezone::format($utc, user_timezone(), $format);
    }
}

if (!function_exists('tz_label')) {
    function tz_label(?string $tz = null): string
    {
        $tz = \Ownswitch\Support\Timezone::normalize($tz ?? user_timezone());
        return \Ownswitch\Support\Timezone::label($tz);
    }
}
