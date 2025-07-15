<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

/**
 * High performance, radix tree based HTTP request router for PHP.
 */
class RadixRouter
{
    /**
     * The tree structure for dynamic routes.
     * @var array<string, mixed>
     */
    public array $tree = [];

    /**
     * Static routes indexed by pattern and method.
     * @var array<string, array<string, mixed>>
     */
    public array $static = [];

    /**
     * Allowed HTTP methods for routes.
     * @var array<int, string>
     */
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Registers a route for one or more HTTP methods and a given pattern.
     *
     * @param string|array<int, string> $methods HTTP method(s) (e.g., 'GET' or ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/users/:id?').
     * @param mixed $handler Handler to associate with the route.
     * @return self
     *
     * @throws InvalidArgumentException If a method is invalid, the route conflicts with an existing route,
     *         a wildcard parameter is not the last segment, or optional parameters are not at the end.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        $pattern = rtrim($pattern, '/');

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
        foreach ($segments as $i => &$segment) {
            if (!str_starts_with($segment, ':')) {
                continue;
            }
            $hasParameter = true;
            if (str_ends_with($segment, '*')) {
                if ($i !== count($segments) - 1) {
                    throw new InvalidArgumentException(
                        "Wildcard parameter can only appear as the last segment in route '$pattern'."
                    );
                }
                $segment = '/wildcard_node';
                continue;
            }
            if (str_ends_with($segment, '?')) {
                $variants = $this->getOptionalParameterVariants($pattern);
                foreach ($variants as $variant) {
                    try {
                        $this->add($methods, $variant, $handler);
                    } catch (InvalidArgumentException $e) {
                        throw new InvalidArgumentException(
                            "Pattern '$pattern' conflicts with an existing route."
                        );
                    }
                }
                return $this;
            }
            $segment = '/parameter_node';
        }
        unset($segment);

        if ($hasParameter) {
            $node = &$this->tree;
            foreach ($segments as $segment) {
                if (
                    ($segment === '/parameter_node' && isset($node['/wildcard_node'])) ||
                    ($segment === '/wildcard_node' && isset($node['/parameter_node']))
                ) {
                    throw new InvalidArgumentException(
                        "Pattern '$pattern' conflicts with an existing route."
                    );
                }
                $node = &$node[$segment];
            }
            foreach ($methods as $method) {
                if (isset($node['/routes_node'][$method])) {
                    throw new InvalidArgumentException(
                        "Pattern $method '$pattern' conflicts with an existing route."
                    );
                }
                $node['/routes_node'][$method] = $handler;
            }
        } else {
            foreach ($methods as $method) {
                if (isset($this->static[$pattern][$method])) {
                    throw new InvalidArgumentException(
                        "Pattern $method '$pattern' conflicts with an existing route."
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

        $params = [];
        $node = $this->tree;
        $segments = explode('/', $path);
        $segCount = count($segments);
        $depth = 0;
        for (; $depth < $segCount; $depth++) {
            $segment = $segments[$depth];
            if (isset($node[$segment])) {
                $node = $node[$segment];
                continue;
            }
            if ($segment !== '' && isset($node['/parameter_node'])) {
                $node = $node['/parameter_node'];
                $params[] = $segment;
                continue;
            }
            break;
        }

        if (isset($node["/wildcard_node"])) {
            $node = $node["/wildcard_node"];
            if ($depth >= $segCount) {
                $params[] = '';
            } else {
                $params[] = implode('/', array_slice($segments, $depth));
            }
        } else {
            if ($depth < $segCount) {
                return ['code' => 404];
            }
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
        $found = false;
        foreach ($segments as $segment) {
            if (str_ends_with($segment, '?')) {
                $found = true;
                $variants[] = implode('/', $current);
                $current[] = rtrim($segment, '?');
            } else {
                if ($found) {
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
