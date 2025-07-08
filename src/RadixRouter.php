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
                $segment = '/parameter_node';
            }
        }
        unset($segment); // Break reference

        // Handle optional segment (pattern ends with '?')
        if (str_ends_with($pattern, '?')) {
            // Add a route for the pattern without the parameter segment
            $optionalPattern = implode('/', array_slice($segments, 0, -1)) . '/';
            $this->add($methods, $optionalPattern, $handler);
        }

        // Handle wildcard segment (pattern ends with '*')
        if (str_ends_with($pattern, '*')) {
            // Replace the last segment with a wildcard node marker
            $segments[count($segments) - 1] = '/wildcard_node';
        }

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

        // Start at the root of the route tree
        $node = $this->routes;

        // Split the path into segments
        $segments = explode('/', $path);

        // Traverse the route tree segment by segment
        foreach ($segments as $depth => $segment) {
            // Exact match for the segment
            if (isset($node[$segment])) {
                $node = $node[$segment];
                continue;
            }
            // Match dynamic segment (e.g., :id)
            if ($segment !== '' && isset($node['/parameter_node'])) {
                $node = $node['/parameter_node'];
                $params[] = $segment;
                continue;
            }
            // Match wildcard segment (e.g., :rest*)
            if (isset($node["/wildcard_node"])) {
                $node = $node["/wildcard_node"];
                // Capture the rest of the path as a single parameter
                $params[] = implode('/', array_slice($segments, $depth));
                break;
            }
            // No matching route found
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

        // If no handler for this method, check allowed methods
        if (empty($node['/allowed_methods'])) {
            return ['code' => 404];
        }

        // Method not allowed for this route
        return [
            'code' => 405,
            'allowed_methods' => $node['/allowed_methods'],
        ];
    }
}
