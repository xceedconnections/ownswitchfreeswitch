<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\App;
use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Services\Auth\SessionService;

/** Rejects state-changing requests without a valid per-session token. */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionService $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $field = (string) App::config('security.csrf.field', '_csrf');
        $header = (string) App::config('security.csrf.header', 'X-CSRF-Token');
        $token = $request->post[$field] ?? $request->header($header);
        if (!is_string($token) || !$this->session->validateCsrf($token)) {
            return new Response('Invalid or missing CSRF token. Reload the page and try again.', 419, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $next($request);
    }
}
