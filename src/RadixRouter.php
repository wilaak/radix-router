<?php

declare(strict_types=1);

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * High-performance radix tree based HTTP request router.
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/radix-router
 */
class RadixRouter
{
    /**
     * Dynamic routes are stored in a radix tree structure.
     */
    public array $tree = [];

    /**
     * Static routes are stored in a separate map for O(1) lookups.
     */
    public array $static = [];

    /**
     * List of allowed HTTP methods during registration.
     */
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Used to track the original pattern during registration of optional parameter variants.
     */
    private ?string $optionalPattern = null;

    /**
     * Add a route for one or more HTTP methods and a given pattern.
     *
     * @param string|list<string> $methods HTTP methods (e.g., ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param mixed $handler Handler to associate with the route.
     *
     * @throws InvalidArgumentException On invalid method, pattern, or route conflict.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (!\str_starts_with($pattern, '/')) {
            $pattern = "/{$pattern}";
        }

        if (\is_array($methods)) {
            if (empty($methods)) {
                throw new InvalidArgumentException(
                    "Invalid HTTP Method: Got empty array for pattern '{$pattern}'"
                );
            }
            foreach ($methods as $method) {
                $this->add($method, $pattern, $handler);
            }
            return $this;
        }

        $method = \strtoupper($methods);
        if (!\in_array($method, $this->allowedMethods, true) && $method !== '*') {
            throw new InvalidArgumentException(
                "Invalid HTTP Method: [{$method}] '{$pattern}': Allowed methods: " . \implode(', ', $this->allowedMethods)
            );
        }
        if (\str_contains($pattern, '//')) {
            throw new InvalidArgumentException(
                "Invalid Pattern: [{$method}] '{$pattern}': Empty segments are not allowed (e.g., '//')"
            );
        }

        $normalizedPattern = \rtrim($pattern, '/');
        $isDynamic = \str_contains($pattern, '/:');

        if (!$isDynamic) {
            if (isset($this->static[$normalizedPattern][$method])) {
                $attempted = $this->optionalPattern ?? $pattern;
                $conflicting = $this->static[$normalizedPattern][$method]['pattern'];
                throw new InvalidArgumentException(
                    "Route Conflict: [{$method}] '{$attempted}': Path is already registered"
                        . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')" : '')
                );
            }
            $this->static[$normalizedPattern][$method] = [
                'code' => 200,
                'handler' => $handler,
                'params' => [],
                'pattern' => $this->optionalPattern ?? $pattern,
            ];
            return $this;
        }

        $NODE_WILDCARD = '/w';
        $NODE_PARAMETER = '/p';
        $NODE_ROUTES = '/r';

        $segments = \explode('/', $normalizedPattern);
        $isOptional = false;
        $params = [];

        foreach ($segments as $i => &$segment) {
            if ($isOptional && !\str_ends_with($segment, '?')) {
                throw new InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Optional parameters are only allowed in the last trailing segments"
                );
            }
            if (!\str_starts_with($segment, ':')) {
                continue;
            }

            $name = \substr($segment, 1);
            if (\str_ends_with($name, '?') || \str_ends_with($name, '*') || \str_ends_with($name, '+')) {
                $name = \substr($name, 0, -1);
            }
            if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': "
                        . "Parameter name '{$name}' must start with a letter or underscore and contain only letters, digits, or underscores"
                );
            }
            if (isset($params[$name])) {
                throw new InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Parameter name '{$name}' cannot be used more than once"
                );
            }
            $params[$name] = $name;

            if (\str_ends_with($segment, '?')) {
                $isOptional = true;
            }

            if (\str_ends_with($segment, '*') || \str_ends_with($segment, '+')) {
                if ($i !== \array_key_last($segments)) {
                    throw new InvalidArgumentException(
                        "Invalid Pattern: [{$method}] '{$pattern}': Wildcard parameters are only allowed as the last segment"
                    );
                }
                $segment = $NODE_WILDCARD;
            } else {
                $segment = $NODE_PARAMETER;
            }
        }
        unset($segment);

        if ($isOptional) {
            $variants = $this->expandOptionalTrailingSegments($pattern);
            $this->optionalPattern = $pattern;
            foreach ($variants as $variant) {
                $this->add($method, $variant, $handler);
            }
            $this->optionalPattern = null;
            return $this;
        }

        $currentNode = &$this->tree;
        foreach ($segments as $segment) {
            $currentNode = &$currentNode[$segment];
        }

        if (isset($currentNode[$NODE_ROUTES][$method])) {
            $attempted = $this->optionalPattern ?? $pattern;
            $conflicting = $currentNode[$NODE_ROUTES][$method]['pattern'];
            throw new InvalidArgumentException(
                "Route Conflict: [{$method}] '{$attempted}': Path is already registered"
                    . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')" : '')
            );
        }
        $currentNode[$NODE_ROUTES][$method] = [
            'code' => 200,
            'handler' => $handler,
            'params' => \array_values($params),
            'pattern' => $this->optionalPattern ?? $pattern,
        ];
        return $this;
    }

    /**
     * List all registered routes, optionally filtered by a request path.
     *
     * @param string|null $path If provided, lists only routes matching this path.
     * @return list<array{method: string, pattern: string, handler: mixed}>
     */
    public function list(?string $path = null): array
    {
        $formatRoute = fn(string $method, array $route): array => [
            'method' => $method,
            'pattern' => $route['pattern'],
            'handler' => $route['handler'],
        ];

        $routes = [];

        if (isset($path)) {
            $result = $this->lookup('*', $path);
            if ($result['code'] !== 405) {
                return [];
            }
            foreach ($result['_routes'] as $method => $route) {
                $routes[] = $formatRoute($method, $route);
            }
            return $routes;
        }

        foreach ($this->static as $methods) {
            foreach ($methods as $method => $route) {
                $routes[] = $formatRoute($method, $route);
            }
        }

        $collect = function ($currentNode) use (&$collect, &$routes, $formatRoute): void {
            $NODE_WILDCARD = '/w';
            $NODE_PARAMETER = '/p';
            $NODE_ROUTES = '/r';

            if (isset($currentNode[$NODE_ROUTES])) {
                foreach ($currentNode[$NODE_ROUTES] as $method => $route) {
                    $routes[] = $formatRoute($method, $route);
                }
            }
            foreach ($currentNode as $segment => $child) {
                if (\str_starts_with($segment, '/')) {
                    continue;
                }
                $collect($child);
            }
            if (isset($currentNode[$NODE_WILDCARD])) {
                $collect($currentNode[$NODE_WILDCARD]);
            }
            if (isset($currentNode[$NODE_PARAMETER])) {
                $collect($currentNode[$NODE_PARAMETER]);
            }
        };
        $collect($this->tree);

        $routes = \array_values(\array_unique($routes, SORT_REGULAR));
        \usort($routes, fn($a, $b): int => $a['pattern'] <=> $b['pattern'] ?: $a['method'] <=> $b['method']);

        return $routes;
    }

    /**
     * List allowed HTTP methods for a given request path.
     *
     * @param string $path Request path (e.g., '/users/123').
     * @return list<string> List of allowed HTTP methods for the path.
     */
    public function methods(string $path): array
    {
        $methods = $this->lookup('*', $path)['allowed_methods'] ?? [];
        if (\in_array('*', $methods, true)) {
            return $this->allowedMethods;
        }
        return $methods;
    }

    /**
     * Lookup a route for a given HTTP method and request path.
     *
     * @param string $method HTTP method (e.g., 'GET', 'POST').
     * @param string $path Request path (e.g., '/users/123').
     * @return array{
     *   code: int,
     *   handler?: mixed,
     *   params?: array<string, string>,
     *   pattern?: string,
     *   allowed_methods?: list<string>
     * }
     */
    public function lookup(string $method, string $path): array
    {
        if ($path !== '' && $path[-1] === '/') {
            $path = \rtrim($path, '/');
        }
        if ($path !== '' && $path[0] !== '/') {
            $path = "/{$path}";
        }

        $params = [];

        $routes = $this->static[$path] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                return $result;
            }
            goto HANDLE_405;
        }

        $NODE_WILDCARD = '/w';
        $NODE_PARAMETER = '/p';
        $NODE_ROUTES = '/r';

        $segments = \explode('/', $path);
        $currentNode = $this->tree;

        foreach ($segments as $i => $segment) {
            if (isset($currentNode[$NODE_WILDCARD])) {
                $wildcardNode = $currentNode[$NODE_WILDCARD];
                $wildcardParams = $params;
                $wildcardOffset = $i;
            }

            if (isset($currentNode[$segment])) {
                $lastStaticNode = $currentNode;
                $lastStaticSegment = $segment;
                $currentNode = $currentNode[$segment];
                continue;
            }

            if ($segment !== '' && isset($currentNode[$NODE_PARAMETER])) {
                $currentNode = $currentNode[$NODE_PARAMETER];
                $params[] = $segment;
                continue;
            }
            goto NO_MATCH;
        }

        $routes = $currentNode[$NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            goto HANDLE_405;
        }

        $routes = $lastStaticNode[$NODE_PARAMETER][$NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $params[] = $lastStaticSegment;
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            goto HANDLE_405;
        }

        $routes = $currentNode[$NODE_WILDCARD][$NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $pattern = $result['pattern'];
                if (\str_ends_with($pattern, '*') || \str_ends_with($pattern, '/*')) {
                    $params[] = '';
                    $result['params'] = \array_combine($result['params'], $params);
                    return $result;
                }
            }

            $optionalWildcards = [];
            foreach ($routes as $routeMethod => $result) {
                $pattern = $result['pattern'];
                if (\str_ends_with($pattern, '*') || \str_ends_with($pattern, '/*')) {
                    $optionalWildcards[$routeMethod] = $result;
                }
            }

            if ($optionalWildcards) {
                $routes = $optionalWildcards;
                $params[] = '';
                goto HANDLE_405;
            }

            NO_MATCH:
            $routes = $wildcardNode[$NODE_ROUTES] ?? null;
            if (isset($routes)) {
                $wildcardParams[] = \implode('/', \array_slice($segments, $wildcardOffset));
                $result = $routes[$method] ?? $routes['*'] ?? null;
                if (isset($result) && $method !== '*') {
                    $result['params'] = \array_combine($result['params'], $wildcardParams);
                    return $result;
                }
                $params = $wildcardParams;
                goto HANDLE_405;
            }
        }

        return ['code' => 404];

        HANDLE_405:
        $allowedMethods = \array_keys($routes);
        if (isset($routes['GET']) && !isset($routes['HEAD'])) {
            if ($method === 'HEAD') {
                $result = $routes['GET'];
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            $allowedMethods[] = 'HEAD';
        }
        return ['code' => 405, 'allowed_methods' => $allowedMethods, '_routes' => $routes];
    }

    /**
     * Generate all pattern variants for optional trailing segments.
     * 
     * For example, '/archive/:year?/:month?' would generate:
     * - '/archive'
     * - '/archive/:year'
     * - '/archive/:year/:month'
     */
    private function expandOptionalTrailingSegments(string $pattern): array
    {
        $segments = \explode('/', \trim($pattern, '/'));
        $count = \count($segments);

        $firstOptional = null;
        for ($i = 0; $i < $count; $i++) {
            if (\str_ends_with($segments[$i], '?')) {
                $firstOptional = $i;
                break;
            }
        }

        $variants = [];
        for ($end = $firstOptional; $end <= $count; $end++) {
            $parts = [];
            foreach (\array_slice($segments, 0, $end) as $segment) {
                $parts[] = \rtrim($segment, '?');
            }
            $variants[] = '/' . \implode('/', $parts);
        }
        return $variants;
    }
}
