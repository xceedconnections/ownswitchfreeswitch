<?php

declare(strict_types=1);

namespace Ownswitch;

use Ownswitch\Http\Request;
use Ownswitch\Http\Response;
use Ownswitch\Middleware\MiddlewareInterface;

/** Small regex router with per-route middleware. Handlers: callable|[class,method]. */
final class Router
{
    /** @var list<array{method:string,regex:string,handler:mixed,middleware:list<string|MiddlewareInterface>}> */
    private array $routes = [];
    /** @var list<string> */
    private array $groupMiddleware = [];
    private string $groupPrefix = '';
    /** @var (callable(string):MiddlewareInterface)|null */
    private $resolver;

    /** @param (callable(string):MiddlewareInterface)|null $resolver */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /** @param list<string> $middleware */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $prevP = $this->groupPrefix;
        $prevM = $this->groupMiddleware;
        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        $define($this);
        $this->groupPrefix = $prevP;
        $this->groupMiddleware = $prevM;
    }

    /** @param list<string|MiddlewareInterface> $middleware */
    public function add(string $method, string $pattern, mixed $handler, array $middleware = []): void
    {
        $full = rtrim($this->groupPrefix . $pattern, '/') ?: '/';
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $m): string {
            return $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $m[1] . '>[^/]+)';
        }, $full);
        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . $regex . '$#',
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    public function get(string $p, mixed $h, array $mw = []): void
    {
        $this->add('GET', $p, $h, $mw);
    }

    public function post(string $p, mixed $h, array $mw = []): void
    {
        $this->add('POST', $p, $h, $mw);
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            $pathMatched = true;
            $method = $request->method === 'HEAD' ? 'GET' : $request->method;
            if ($route['method'] !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $request->setAttr('route_params', $params);

            $core = function (Request $req) use ($route, $params): Response {
                return $this->invoke($route['handler'], $req, $params);
            };
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                fn (callable $next, $mw) => fn (Request $req): Response => $this->resolve($mw)->handle($req, $next),
                $core
            );
            return $pipeline($request);
        }
        return $pathMatched
            ? new Response('405 Method Not Allowed', 405, ['Content-Type' => 'text/plain; charset=UTF-8'])
            : new Response('404 Not Found', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    private function resolve(string|MiddlewareInterface $mw): MiddlewareInterface
    {
        if ($mw instanceof MiddlewareInterface) {
            return $mw;
        }
        if ($this->resolver === null) {
            throw new \LogicException('No middleware resolver configured');
        }
        return ($this->resolver)($mw);
    }

    /** @param array<string,string> $params */
    private function invoke(mixed $handler, Request $req, array $params): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $result = $handler($req, $params);
        return $result instanceof Response ? $result : Response::html((string) $result);
    }
}
