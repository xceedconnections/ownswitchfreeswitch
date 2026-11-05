<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Support\TenantScope;

/** Derives the TenantScope from the authenticated user. Never trusts request input. */
final class TenantMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response
    {
        $user = $request->attr('user');
        if (!is_array($user)) {
            return new Response('403 Forbidden', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $scope = TenantScope::fromUser($user);
        if (!$scope->isGlobal()
            && $scope->resellerId === null && $scope->customerId === null) {
            // Non-global role without a tenant binding: misconfigured account, deny.
            return new Response('403 Forbidden - account has no tenant assignment.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $request->setAttr('tenant', $scope);
        return $next($request);
    }
}
