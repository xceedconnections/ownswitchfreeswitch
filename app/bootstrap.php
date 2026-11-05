<?php

declare(strict_types=1);

/**
 * Ownswitch bootstrap: PSR-4 autoloader (no Composer needed), helpers, config.
 * Requires PHP >= 8.2 (targets 8.3).
 */

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('Ownswitch requires PHP 8.2 or newer (8.3 recommended).');
}

define('OWNSWITCH_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ownswitch\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__ . '/Support/helpers.php';

\Ownswitch\App::boot(OWNSWITCH_ROOT);

if (!extension_loaded('bcmath')) {
    http_response_code(500);
    exit('The bcmath PHP extension is required (money is never handled as float).');
}

if (\Ownswitch\App::config('app.debug')) {
    ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
