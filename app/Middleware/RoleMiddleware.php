<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;

/** Allows only listed roles. Must run after AuthMiddleware. Fails closed. */
final class RoleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $allowed */
    public function __construct(private array $allowed)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attr('user');
        if (!is_array($user) || !in_array($user['role'] ?? null, $this->allowed, true)) {
            $body = '403 Forbidden - your role does not permit this action.';
            return $request->wantsJson()
                ? Response::json(['error' => 'forbidden'], 403)
                : new Response($body, 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return $next($request);
    }
}
