<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

class RadixRouter
{
    public array $routes = [];

    public array $allowedMethods = [
        'GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'
    ];

    /**
     * Adds a route for given HTTP methods and pattern.
     *
     * @param string|array<int, string> $methods HTTP method(s) (e.g., 'GET' or ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/*', '/users/:id?').
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
                throw new InvalidArgumentException('Method must be a non-empty string.');
            }
            $method = strtoupper($method);
            if (!in_array($method, $this->allowedMethods, true)) {
                throw new InvalidArgumentException("Invalid HTTP method: $method");
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
                $optionalPattern = implode('/', array_slice($segments, 0, -1)) . '/';
                $this->add($methods, $optionalPattern, $handler);
            } else if (str_ends_with($pattern, '*')) {
                $segments[count($segments) - 1] = '/wildcard_node';
            }

            $node = &$this->routes;
            foreach ($segments as $segment) {
                $node = &$node[$segment];
            }
        } else {
            $node = &$this->routes['/static'][$pattern];
        }

        foreach ($methods as $method) {
            if (isset($node["/$method"])) {
                throw new InvalidArgumentException("Route $method $pattern conflicts with existing route.");
            }
            $node["/$method"] = $handler;
        }

        if (isset($node['/allowed_methods'])) {
            $node['/allowed_methods'] = array_unique(array_merge($node['/allowed_methods'], $methods));
        } else {
            $node['/allowed_methods'] = $methods;
        }

        return $this;
    }

    /**
     * Looks up a route based on the HTTP method and path.
     *
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @param string $path The request path (e.g., '/users/123').
     * @return array{
     *     code: int, // 200 (found), 404 (not found), or 405 (method not allowed)
     *     handler?: mixed, // Present if code is 200
     *     params?: array<int, string>, // Present if code is 200
     *     allowed_methods?: array<int, string> // Present if code is 405
     * }
     */
    public function lookup(string $method, string $path): array
    {
        if (isset($this->routes['/static'][$path])) {
            if (isset($this->routes['/static'][$path]["/$method"])) {
                return [
                    'code' => 200,
                    'handler' => $this->routes['/static'][$path]["/$method"],
                    'params' => [],
                ];
            }
            return [
                'code' => 405,
                'allowed_methods' => $this->routes['/static'][$path]['/allowed_methods'],
            ];
        }

        $params = [];
        $node = $this->routes;
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

        if (isset($node["/$method"])) {
            return [
                'code' => 200,
                'handler' => $node["/$method"],
                'params' => $params,
            ];
        }

        if (empty($node['/allowed_methods'])) {
            return ['code' => 404];
        }

        return [
            'code' => 405,
            'allowed_methods' => $node['/allowed_methods'],
        ];
    }
}
