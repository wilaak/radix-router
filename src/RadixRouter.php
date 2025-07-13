<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

class RadixRouter
{
    public array $tree = [];
    public array $static = [];

    public array $allowedMethods = [
        'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'
    ];

    /**
     * Adds a route for given HTTP methods and pattern.
     *
     * @param string|array<int, string> $methods HTTP method(s) (e.g., 'GET' or ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/users/:id?').
     * @param mixed $handler Handler for the route.
     * @return self
     *
     * @throws InvalidArgumentException If a method is invalid or the route conflicts.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (is_string($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as &$method) {
            if (!is_string($method) || empty($method)) {
                throw new InvalidArgumentException(
                    "Method must be a non-empty string for route '$pattern'."
                );
            }
            $method = strtoupper($method);
            if (!in_array($method, $this->allowedMethods, true)) {
                throw new InvalidArgumentException(
                    "Invalid HTTP method: $method for route '$pattern'."
                );
            }
        }
        unset($method);

        $segments = explode('/', $pattern);
        $hasParameter = false;
        foreach ($segments as &$segment) {
            if (str_starts_with($segment, ':')) {
                $segment = '/parameter_node';
                $hasParameter = true;
            }
        }
        unset($segment);

        if ($hasParameter) {
            if (str_ends_with($pattern, '?')) {
                $newPattern = implode('/', array_slice(explode('/', $pattern), 0, -1)) . '/';
                $this->add($methods, $newPattern, $handler);
            } else if (str_ends_with($pattern, '*')) {
                $segments[count($segments) - 1] = '/wildcard_node';
            }

            $node = &$this->tree;
            foreach ($segments as $segment) {
                if ($segment === '/parameter_node' && isset($node['/wildcard_node'])) {
                    throw new InvalidArgumentException(
                        "Route '$pattern' conflicts with existing wildcard route."
                    );
                }
                if ($segment === '/wildcard_node' && isset($node['/parameter_node'])) {
                    throw new InvalidArgumentException(
                        "Wildcard route '$pattern' is shadowed by an existing route."
                    );
                }
                $node = &$node[$segment];
            }

            foreach ($methods as $method) {
                if (isset($node['/routes_node'][$method])) {
                    throw new InvalidArgumentException(
                        "Route $method '$pattern' conflicts with existing route."
                    );
                }
                $node['/routes_node'][$method] = $handler;
            }
        } else {
            foreach ($methods as $method) {
                if (isset($this->static[$pattern][$method])) {
                    throw new InvalidArgumentException(
                        "Route $method '$pattern' conflicts with existing route."
                    );
                }
                $this->static[$pattern][$method] = $handler;
            }
        }

        return $this;
    }

    /**
     * Looks up a route based on the HTTP method and path.
     *
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @param string $path The request path (e.g., '/users/123').
     * @return array{code: int, handler?: mixed, params?: array<int, string>, allowed_methods?: array<int, string>}
     *   - code: 200 (found), 404 (not found), or 405 (method not allowed).
     *   - handler: Present if code is 200.
     *   - params: Present if code is 200.
     *   - allowed_methods: Present if code is 405.
     */
    public function lookup(string $method, string $path): array
    {
        if (isset($this->static[$path])) {
            if (isset($this->static[$path][$method])) {
                return [
                    'code' => 200,
                    'handler' => $this->static[$path][$method],
                    'params' => [],
                ];
            }
            return [
                'code' => 405,
                'allowed_methods' => array_keys($this->static[$path]),
            ];
        }

        $params = [];
        $node = $this->tree;
        $segments = explode('/', $path);

        foreach ($segments as $depth => $segment) {
            if (isset($node[$segment])) {
                $node = $node[$segment];
                continue;
            }
            if ($segment !== '' && isset($node['/parameter_node'])) {
                $node = $node['/parameter_node'];
                $params[] = $segment;
                continue;
            }
            if (isset($node["/wildcard_node"])) {
                $node = $node["/wildcard_node"];
                $params[] = implode('/', array_slice($segments, $depth));
                break;
            }
            return ['code' => 404];
        }

        if (isset($node['/routes_node'][$method])) {
            return [
                'code' => 200,
                'handler' => $node['/routes_node'][$method],
                'params' => $params,
            ];
        }

        if (!isset($node['/routes_node'])) {
            return ['code' => 404];
        }

        return [
            'code' => 405,
            'allowed_methods' => array_keys($node['/routes_node']),
        ];
    }
}
