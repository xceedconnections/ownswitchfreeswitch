<?php

declare(strict_types=1);

// PHP built-in server: let it serve real static files (php -S localhost:8080 -t public public/index.php)
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    if (is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require __DIR__ . '/../app/bootstrap.php';

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Router;

$request = Request::fromGlobals();
App::setRequest($request);

try {
    App::session()->start();
    $router = new Router(static fn (string $spec) => App::middleware($spec));
    require OWNSWITCH_ROOT . '/app/routes.php';
    $response = $router->dispatch($request);
} catch (\Throwable $e) {
    error_log('[ownswitch] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $detail = '';
    if (App::config('app.debug')) {
        $detail = "\n\n" . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
    } elseif ($e instanceof \PDOException) {
        $detail = '';
    }
    $hint = $e instanceof \PDOException ? "\nDatabase error. Check config/.env and run: php bin/migrate.php" : '';
    $response = new Response(
        "<!doctype html><meta charset=\"utf-8\"><title>Server error</title><pre>500 Internal Server Error" . htmlspecialchars($hint . $detail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
        500
    );
}

$response->withSecurityHeaders()->send();
