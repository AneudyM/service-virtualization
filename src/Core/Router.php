<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight router — maps HTTP method + path pattern to handler callables.
 * Supports path parameters: /api/compliance/sessions/{id}
 */
final class Router
{
    /** @var array<string, array{pattern: string, handler: callable}> */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): self
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->pathToRegex($path),
            'path'    => $path,
            'handler' => $handler,
        ];
        return $this;
    }

    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    public function delete(string $path, callable $handler): self
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function put(string $path, callable $handler): self
    {
        return $this->add('PUT', $path, $handler);
    }

    /**
     * Dispatch the current request. Returns ['handler' => callable, 'params' => [...]] or null.
     */
    public function dispatch(string $method, string $uri): ?array
    {
        $method = strtoupper($method);
        // Strip query string
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extract named params only
                $params = array_filter($matches, fn($key) => is_string($key), ARRAY_FILTER_USE_KEY);
                return ['handler' => $route['handler'], 'params' => $params];
            }
        }

        return null;
    }

    private function pathToRegex(string $path): string
    {
        // Convert {param} to named capture groups
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }
}
