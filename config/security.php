<?php

declare(strict_types=1);

use Ownswitch\Support\Env;

return [
    'session' => [
        'name'     => 'OWNSWITCHSESS',
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 7200),
        'secure'   => (bool) Env::get('SESSION_SECURE', false),
        'samesite' => 'Lax',
    ],
    'csrf' => [
        'field'  => '_csrf',
        'header' => 'X-CSRF-Token',
    ],
    'auth' => [
        'min_password_length'   => 1,
        'max_failed_logins'     => 5,
        'lockout_minutes'       => 15,
        'ip_window_minutes'     => 10,
        'ip_max_failures'       => 8,
    ],
    // REMOTE_ADDR values allowed to supply X-Forwarded-For.
    'trusted_proxies' => [],
    // Content-Security-Policy: no inline scripts/styles. Bootstrap is loaded from jsDelivr with SRI.
    'csp' => "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://cdn.jsdelivr.net; "
           . "img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; "
           . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
];
