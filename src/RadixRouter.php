<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

/**
 * Simple radix tree based HTTP request router for PHP. 
 */
class RadixRouter
{
    public array $tree = [];

    public array $static = [];

    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Registers a route for one or more HTTP methods and a given pattern.
     *
     * @param string|array<int, string> $methods HTTP method(s) (e.g., 'GET' or ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/users/:id?').
     * @param mixed $handler Handler to associate with the route.
     * @return self
     *
     * @throws InvalidArgumentException On invalid method, conflicting route, or invalid pattern.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        $pattern = rtrim($pattern, '/');
        if (is_string($methods)) {
            $methods = [$methods];
        }

        foreach ($methods as &$method) {
            if (!is_string($method) || $method === '') {
                throw new InvalidArgumentException("Method must be a non-empty string for pattern '$pattern'.");
            }
            $method = strtoupper($method);
            if (!in_array($method, $this->allowedMethods, true)) {
                throw new InvalidArgumentException("Invalid HTTP method: $method for pattern '$pattern'.");
            }
        }
        unset($method);

        $segments = explode('/', $pattern);
        $hasParam = false;

        foreach ($segments as $i => &$segment) {
            if (!str_starts_with($segment, ':')) {
                continue;
            }
            $hasParam = true;
            if (str_ends_with($segment, '?')) {
                foreach ($this->getOptionalParameterVariants($pattern) as $variant) {
                    $this->add($methods, $variant, $handler);
                }
                return $this;
            }
            if (str_ends_with($segment, '*')) {
                if ($i !== array_key_last($segments)) {
                    throw new InvalidArgumentException("Wildcard parameter must be last in pattern '$pattern'.");
                }
                $segment = '/wildcard_node';
            } else {
                $segment = '/parameter_node';
            }
        }
        unset($segment);

        if ($hasParam) {
            $node = &$this->tree;
            foreach ($segments as $segment) {
                $node = &$node[$segment];
            }
            foreach ($methods as $method) {
                if (isset($node['/routes_node'][$method])) {
                    throw new InvalidArgumentException("Pattern $method '$pattern' conflicts with an existing route.");
                }
                $node['/routes_node'][$method] = $handler;
            }
        } else {
            foreach ($methods as $method) {
                if (isset($this->static[$pattern][$method])) {
                    throw new InvalidArgumentException("Pattern $method '$pattern' conflicts with an existing route.");
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
        $path = rtrim($path, '/');
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

        $segments = explode('/', $path);
        $params = [];
        $node = $this->tree;

        foreach ($segments as $i => $segment) {
            if (isset($node['/wildcard_node'])) {
                $wildcard = [
                    'node' => $node['/wildcard_node'],
                    'params' => $params,
                    'index' => $i,
                ];
            }
            if (isset($node[$segment])) {
                $node = $node[$segment];
            } elseif ($segment !== '' && isset($node['/parameter_node'])) {
                $node = $node['/parameter_node'];
                $params[] = $segment;
            } else {
                $node = [];
                break;
            }
        }

        if (isset($node['/routes_node'][$method])) {
            return [
                'code' => 200,
                'handler' => $node['/routes_node'][$method],
                'params' => $params,
            ];
        } else if (isset($node['/routes_node'])) {
            return [
                'code' => 405,
                'allowed_methods' => array_keys($node['/routes_node']),
            ];
        }

        if (isset($node['/wildcard_node'])) {
            $wildcard = [
                'node' => $node['/wildcard_node'],
                'params' => $params,
                'index' => $i + 1,
            ];
        }

        if (isset($wildcard['node']['/routes_node'][$method])) {
            return [
                'code' => 200,
                'handler' => $wildcard['node']['/routes_node'][$method],
                'params' => array_merge(
                    $wildcard['params'],
                    [implode('/', array_slice($segments, $wildcard['index']))]
                ),
            ];
        }

        if (isset($wildcard['node']['/routes_node'])) {
            return [
                'code' => 405,
                'allowed_methods' => array_keys($wildcard['node']['/routes_node']),
            ];
        }

        return ['code' => 404];
    }

    /**
     * Generates all route pattern variants for optional parameters.
     *
     * E.g. '/users/:id?/:action?' => ['/users', '/users/:id', '/users/:id/:action']
     *
     * @param string $pattern
     * @return array<int, string>
     * 
     * @throws InvalidArgumentException If optional parameters are not at the end of the route pattern.
     */
    private function getOptionalParameterVariants(string $pattern): array
    {
        $segments = explode('/', $pattern);
        $variants = [];
        $current = [];
        $optionalParamStarted = false;
        foreach ($segments as $segment) {
            if (str_ends_with($segment, '?')) {
                $optionalParamStarted = true;
                $variants[] = implode('/', $current);
                $current[] = rtrim($segment, '?');
            } else {
                if ($optionalParamStarted) {
                    throw new InvalidArgumentException(
                        "Optional parameters must be at the end of the route pattern '$pattern'."
                    );
                }
                $current[] = $segment;
            }
        }
        $variants[] = implode('/', $current);
        return $variants;
    }
}
