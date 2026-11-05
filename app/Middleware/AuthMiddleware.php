<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Repositories\UserRepository;
use Ownswitch\Services\Auth\SessionService;

/** Requires a logged-in, still-active user (re-checked against DB each request). */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionService $session, private ?UserRepository $users = null)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $this->session->start();
        $uid = $this->session->userId();
        $user = null;
        if ($uid !== null && $this->users !== null) {
            $user = $this->users->findWithRole($uid);
            if ($user !== null && $user['status'] !== 'active') {
                $user = null;
            }
        }
        if ($user === null) {
            $this->session->destroy();
            if ($request->wantsJson()) {
                return Response::json(['error' => 'unauthenticated'], 401);
            }
            return Response::redirect(($request->basePath) . '/login');
        }
        unset($user['password_hash']);
        $request->setAttr('user', $user);
        return $next($request);
    }
}
