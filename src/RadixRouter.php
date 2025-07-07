<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

class RadixRouter
{
    public array $routes = [];

    /**
     * Adds a route for given HTTP methods and pattern.
     *
     * @param array $methods HTTP methods (e.g., ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id').
     * @param mixed $handler Handler for the route.
     */
    public function add(array $methods, string $pattern, mixed $handler): self
    {
        // Validate HTTP methods
        foreach ($methods as &$method) {
            if (!is_string($method) || empty($method)) {
                throw new InvalidArgumentException('Method must be a non-empty string.');
            }
            $method = strtoupper($method);
            if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'])) {
                throw new InvalidArgumentException("Invalid HTTP method: $method");
            }
        }
        unset($method); // Break reference

        // Split the pattern into segments
        $segments = explode('/', $pattern);

        // Rename dynamic segments
        foreach ($segments as &$segment) {
            if (str_starts_with($segment, ':')) {
                $segment = '/dynamic_node';
            }
        }
        unset($segment); // Break reference

        // Build the route tree
        $node = &$this->routes;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }

        // Store the handler at the current node
        foreach ($methods as $method) {
            if (isset($node["/$method"])) {
                throw new InvalidArgumentException("Route $method $pattern conflicts with existing route.");
            }
            $node["/$method"] = $handler;
        }

        // Store allowed methods for this node
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
     *     handler?: callable, // Present if code is 200
     *     params?: array<int, string>, // Present if code is 200
     *     allowed_methods?: array<int, string> // Present if code is 405
     * }
     */
    public function lookup(string $method, string $path): mixed
    {
        // Holds parameters for dynamic segments
        $params = [];

        // Traverse the route tree
        $node = $this->routes;
        foreach (explode('/', $path) as $segment) {
            if (isset($node[$segment])) {
                $node = $node[$segment];
                continue;
            }

            if ($segment !== '' && isset($node['/dynamic_node'])) {
                $node = $node['/dynamic_node'];
                $params[] = $segment;
                continue;
            }

            return ['code' => 404];
        }

        // Check if the method exists at the current node
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
