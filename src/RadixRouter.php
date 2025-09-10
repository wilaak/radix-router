<?php

declare(strict_types=1);

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * High-performance radix tree based HTTP request router
 *
 * @license WTFPL-2.1
 * @link    https://github.com/Wilaak/RadixRouter
 */
class RadixRouter
{
    public array $tree = [];
    public array $static = [];
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    private ?string $parentPattern = null;
    private const string NODE_WILDCARD = '/w';
    private const string NODE_PARAMETER = '/p';
    private const string NODE_ROUTES = '/r';

    /**
     * Adds a route for one or more HTTP methods and a given pattern.
     *
     * @param string|array<int, string> $methods HTTP methods (e.g., ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param mixed $handler Handler to associate with the route.
     *
     * @throws InvalidArgumentException On invalid method, invalid route pattern or route conflicts.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (!\is_array($methods)) {
            $methods = [$methods];
        }
        if (empty($methods)) {
            throw new InvalidArgumentException(
                "At least one HTTP method must be specified for route pattern '{$pattern}'."
            );
        }
        foreach ($methods as &$method) {
            if (!\is_string($method) || empty($method)) {
                throw new InvalidArgumentException(
                    "Invalid HTTP method for route pattern '{$pattern}': "
                    . "HTTP method must be a non-empty string such as 'GET' or 'POST'."
                );
            }
            $method = \strtoupper($method);
            if (!\in_array($method, $this->allowedMethods, true)) {
                throw new InvalidArgumentException(
                    "Unsupported HTTP method '{$method}' for route pattern '{$pattern}': "
                    . "Allowed methods are: "
                    . \implode(', ', $this->allowedMethods) . "."
                );
            }
        }
        unset($method);

        if (!\str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException(
                "Invalid route pattern '{$pattern}': "
                . "Route patterns must begin with a forward slash ('/'). "
            );
        }
        if (\str_contains($pattern, '//')) {
            throw new InvalidArgumentException(
                "Invalid route pattern '{$pattern}': "
                . "Route patterns cannot contain empty segments (e.g., '//')."
            );
        }

        $normalizedPattern = \rtrim($pattern, '/');
        $isDynamic = \str_contains($pattern, '/:');
        if (!$isDynamic) {
            if (empty($normalizedPattern)) {
                $normalizedPattern = '/';
            }
            $result = [
                'code' => 200,
                'handler' => $handler,
                'params' => [],
                'pattern' => $this->parentPattern ?? $pattern,
            ];
            $routes = &$this->static[$normalizedPattern];
            foreach ($methods as $method) {
                if (isset($routes[$method])) {
                    $parentPattern = $routes[$method]['pattern'] ?? $pattern;
                    throw new InvalidArgumentException(
                        "Cannot add route '[{$method}] {$pattern}': this route is already registered at '[{$method}] {$parentPattern}'."
                    );
                }
                $routes[$method] = $result;
            }
            if ($normalizedPattern !== '/') {
                $this->static["$normalizedPattern/"] = $routes;
            }
            return $this;
        }

        $segments = \explode('/', $normalizedPattern);
        $isOptional = false;
        $parameters = [];
        foreach ($segments as $i => &$segment) {
            if (!\str_starts_with($segment, ':')) {
                continue;
            }

            $name = \substr($segment, 1);
            if (\str_ends_with($name, '*') || \str_ends_with($name, '?')) {
                $name = \substr($name, 0, -1);
            }
            $invalid = \extension_loaded('ctype')
                ? empty($name) || (!\ctype_alpha($name[0]) && $name[0] !== '_') || !\ctype_alnum(\str_replace('_', '', $name))
                : empty($name) || !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);

            if ($invalid) {
                throw new InvalidArgumentException(
                    "Invalid parameter name '{$name}' in route pattern '{$pattern}': "
                    . "Parameter names must start with a letter or underscore, contain only alphanumeric characters or underscores, and cannot be empty (e.g., ':user_id')."
                );
            }
            if (\in_array($name, $parameters, true)) {
                throw new InvalidArgumentException(
                    "Duplicate parameter name '{$name}' in route pattern '{$pattern}': "
                    . "Each parameter name must be unique within a route pattern."
                );
            }
            $parameters[] = $name;

            if (\str_ends_with($segment, '?')) {
                $isOptional = true;
            }
            if (\str_ends_with($segment, '*')) {
                if ($i !== \array_key_last($segments)) {
                    throw new InvalidArgumentException(
                        "Invalid route pattern '{$pattern}': "
                        . "Wildcard parameters (e.g., ':param*') must be the last segment in the route pattern."
                    );
                }
                $segment = self::NODE_WILDCARD;
            } else {
                $segment = self::NODE_PARAMETER;
            }
        }
        unset($segment);

        if ($isOptional) {
            $variants = $this->expandOptionalPattern($normalizedPattern);
            $this->parentPattern = $pattern;
            foreach ($variants as $variant) {
                $this->add($methods, $variant, $handler);
            }
            $this->parentPattern = null;
            return $this;
        }

        $node = &$this->tree;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }
        $route = [
            'handler' => $handler,
            'param_names' => $parameters,
            'pattern' => $this->parentPattern ?? $pattern,
        ];
        $routes = &$node[self::NODE_ROUTES];
        foreach ($methods as $method) {
            if (isset($routes[$method])) {
                $parentPattern = $routes[$method]['pattern'];
                throw new InvalidArgumentException(
                    "Cannot add route '[{$method}] {$pattern}': this route is already registered at '[{$method}] {$parentPattern}'."
                );
            }
            $routes[$method] = $route;
        }
        $node[''][self::NODE_ROUTES] = $routes;
        return $this;
    }

    /**
     * Looks up a route based on the HTTP method and path.
     *
     * @param string $method The HTTP method (e.g., 'GET', 'POST').
     * @param string $path The request path (e.g., '/users/123').
     * @return array{
     *     code: int,
     *     handler?: mixed,
     *     params?: array<string, string>,
     *     allowed_methods?: list<string>
     * }
     */
    public function lookup(string $method, string $path): array
    {
        $routes = $this->static[$path] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? null;
            if (isset($result)) {
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes)];
        }

        $segments = \explode('/', $path);
        $params = [];
        $node = $this->tree;
        foreach ($segments as $i => $segment) {
            if (isset($node[self::NODE_WILDCARD])) {
                $wildcardNode = $node[self::NODE_WILDCARD];
                $wildcardParams = $params;
                $wildcardIndex = $i;
            }
            if (isset($node[$segment])) {
                $node = $node[$segment];
            } elseif (!empty($segment) && isset($node[self::NODE_PARAMETER])) {
                $node = $node[self::NODE_PARAMETER];
                $params[] = $segment;
            } else {
                unset($node);
                break;
            }
        }

        if (isset($node[self::NODE_ROUTES])) {
            $route = $node[self::NODE_ROUTES][$method] ?? null;
            if (isset($route)) {
                $params = \array_combine($route['param_names'], $params);
                return ['code' => 200, 'handler' => $route['handler'], 'params' => $params];
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($node[self::NODE_ROUTES])];
        }

        if (isset($node[self::NODE_WILDCARD])) {
            $wildcardNode = $node[self::NODE_WILDCARD];
            $wildcardParams = $params;
            $wildcardIndex = $i + 1;
        }

        if (isset($wildcardNode[self::NODE_ROUTES])) {
            $route = $wildcardNode[self::NODE_ROUTES][$method] ?? null;
            if (isset($route)) {
                $params = \array_merge(
                    $wildcardParams,
                    [\implode('/', \array_slice($segments, $wildcardIndex))]
                );
                $params = \array_combine($route['param_names'], $params);
                return ['code' => 200, 'handler' => $route['handler'], 'params' => $params];
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($wildcardNode[self::NODE_ROUTES])];
        }

        return ['code' => 404];
    }

    /**
     * Generates all route pattern variants for optional parameters.
     *
     * For example, '/users/:id?/:action?' produces:
     *   ['/users', '/users/:id', '/users/:id/:action']
     *
     * @return array<int, string> List of route pattern variants.
     *
     * @throws InvalidArgumentException If optional parameters are not in the last trailing segments.
     */
    private function expandOptionalPattern(string $pattern): array
    {
        $segments = \explode('/', $pattern);
        $variants = [];
        $current = [];
        $found = false;

        foreach ($segments as $segment) {
            $isOptional = \str_ends_with($segment, '?');

            if ($isOptional) {
                $found = true;
                $variant = \implode('/', $current);
                $variants[] = empty($variant) ? '/' : $variant;
                $current[] = \substr($segment, 0, -1);
            } else {
                if ($found) {
                    throw new InvalidArgumentException(
                        "Invalid route pattern '{$pattern}': "
                        . "Optional parameters (e.g., ':param?') must only appear "
                        . 'in the last trailing segments of the route pattern.'
                    );
                }
                $current[] = $segment;
            }
        }

        $variants[] = \implode('/', $current);

        return $variants;
    }

    /**
    * Retrieves all routes currently registered in the router.
     *
     * @return array<int, array{
     *     method: string,
     *     handler: mixed,
     *     pattern: string
     * }>
     */
    public function list(): array
    {
        $seen = [];
        $routes = [];
        $extract = function ($node) use (&$extract, &$routes, &$seen) {
            if (!\is_array($node)) {
                return;
            }
            foreach ($node as $key => $child) {
                if ($key === self::NODE_ROUTES && \is_array($child)) {
                    foreach ($child as $method => $route) {
                        if ($seen[$method . $route['pattern']] ?? false) {
                            continue;
                        }
                        $seen[$method . $route['pattern']] = true;
                        $routes[] = [
                            'method' => $method,
                            'pattern' => $route['pattern'],
                            'handler' => $route['handler'],
                        ];
                    }
                } else {
                    $extract($child);
                }
            }
        };
        $extract($this->tree);
        foreach ($this->static as $pattern => $methods) {
            foreach ($methods as $method => $route) {
                if ($seen[$method . $route['pattern']] ?? false) {
                    continue;
                }
                $seen[$method . $route['pattern']] = true;
                $routes[] = [
                    'method' => $method,
                    'pattern' => $route['pattern'],
                    'handler' => $route['handler'],
                ];
            }
        }
        return $routes;
    }
}
