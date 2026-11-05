<?php

declare(strict_types=1);

namespace Ownswitch\Middleware;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;

interface MiddlewareInterface
{
    /** @param callable(Request):Response $next */
    public function handle(Request $request, callable $next): Response;
}
