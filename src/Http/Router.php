<?php

declare(strict_types=1);

namespace SupportAI\Http;

use SupportAI\Support\Container;

/**
 * Small regex-free-ish router. Routes are registered with method + path
 * patterns using {param} placeholders. Handlers are [class, method] pairs
 * resolved lazily through the container so controllers get their deps injected.
 */
final class Router
{
    /** @var array<int,array{method:string,pattern:string,handler:mixed,middleware:array}> */
    private array $routes = [];

    /** @var array<string,callable> */
    private array $middleware = [];

    public function __construct(private Container $container)
    {
    }

    public function get(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('GET', $path, $handler, $mw);
    }

    public function post(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('POST', $path, $handler, $mw);
    }

    public function put(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('PUT', $path, $handler, $mw);
    }

    public function delete(string $path, mixed $handler, array $mw = []): void
    {
        $this->add('DELETE', $path, $handler, $mw);
    }

    public function registerMiddleware(string $name, callable $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    private function add(string $method, string $path, mixed $handler, array $mw): void
    {
        $this->routes[] = [
            'method'     => $method,
            'pattern'    => $path,
            'handler'    => $handler,
            'middleware' => $mw,
        ];
    }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->path);
            if ($params === null) {
                continue;
            }

            // Run middleware chain; any middleware may short-circuit by returning false.
            foreach ($route['middleware'] as $name) {
                $mw = $this->middleware[$name] ?? null;
                if ($mw && $mw($request) === false) {
                    return; // middleware already sent a response
                }
            }

            $this->call($route['handler'], $request, $params);
            return;
        }

        Response::error('Not found', 404);
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    private function call(mixed $handler, Request $request, array $params): void
    {
        if (is_callable($handler)) {
            $handler($request, $params);
            return;
        }
        [$class, $method] = $handler;
        $controller = $this->container->has($class) ? $this->container->get($class) : new $class();
        $controller->{$method}($request, $params);
    }
}
