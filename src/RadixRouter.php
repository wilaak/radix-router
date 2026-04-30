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
     * Dynamic routes are stored in a tree. See self::NODE_STRUCT for slot layout.
     * 
     * Note: Structure may change in future, do not rely on internal format of this property.
     */
    public array $tree = self::NODE_STRUCT;

    /**
     * Static routes are stored in a separate map for O(1) lookups.
     * 
     * Note: Structure may change in future, do not rely on internal format of this property.
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

    private const NODE_STATIC   = 0;
    private const NODE_PARAM    = 1;
    private const NODE_WILDCARD = 2;
    private const NODE_ROUTES   = 3;

    private const NODE_STRUCT = [
        self::NODE_STATIC   => null,
        self::NODE_PARAM    => null,
        self::NODE_WILDCARD => null,
        self::NODE_ROUTES   => null,
    ];

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
            // Store everything for a simple ref-bump return during lookups.
            $this->static[$normalizedPattern][$method] = [
                'code' => 200,
                'handler' => $handler,
                'params' => [],
                'pattern' => $this->optionalPattern ?? $pattern,
            ];
            return $this;
        }

        $markerWildcard = self::NODE_WILDCARD;
        $markerParameter = self::NODE_PARAM;

        $segments = \explode('/', \substr($normalizedPattern, 1));
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
                $segment = $markerWildcard;
            } else {
                $segment = $markerParameter;
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
            if ($segment === $markerParameter) {
                $currentNode[self::NODE_PARAM] ??= self::NODE_STRUCT;
                $currentNode = &$currentNode[self::NODE_PARAM];
            } elseif ($segment === $markerWildcard) {
                $currentNode[self::NODE_WILDCARD] ??= self::NODE_STRUCT;
                $currentNode = &$currentNode[self::NODE_WILDCARD];
            } else {
                $currentNode[self::NODE_STATIC][$segment] ??= self::NODE_STRUCT;
                $currentNode = &$currentNode[self::NODE_STATIC][$segment];
            }
        }

        if (isset($currentNode[self::NODE_ROUTES][$method])) {
            $attempted = $this->optionalPattern ?? $pattern;
            $conflicting = $currentNode[self::NODE_ROUTES][$method]['pattern'];
            throw new InvalidArgumentException(
                "Route Conflict: [{$method}] '{$attempted}': Path is already registered"
                    . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')" : '')
            );
        }
        $currentNode[self::NODE_ROUTES] ??= [];
        // Store everything for a simple ref-bump return during lookups.
        $currentNode[self::NODE_ROUTES][$method] = [
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

        $collect = function (array $currentNode) use (&$collect, &$routes, $formatRoute): void {
            if ($currentNode[self::NODE_ROUTES] !== null) {
                foreach ($currentNode[self::NODE_ROUTES] as $method => $route) {
                    $routes[] = $formatRoute($method, $route);
                }
            }
            foreach (($currentNode[self::NODE_STATIC] ?? []) as $child) {
                $collect($child);
            }
            if ($currentNode[self::NODE_WILDCARD] !== null) {
                $collect($currentNode[self::NODE_WILDCARD]);
            }
            if ($currentNode[self::NODE_PARAM] !== null) {
                $collect($currentNode[self::NODE_PARAM]);
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
        if ($path !== '') {
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            if ($path[-1] === '/') {
                $path = \rtrim($path, '/');
            }
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

        $wildcardNode = null;
        $wildcardParams = [];
        $wildcardSegIdx = 0;
        $lastStaticNode = $this->tree;
        $lastStaticSegment = '';

        $currentNode = $this->tree;
        $segments = $path !== '' ? \explode('/', \substr($path, 1)) : [];

        foreach ($segments as $idx => $segment) {
            if (isset($currentNode[self::NODE_WILDCARD])) {
                $wildcardNode = $currentNode[self::NODE_WILDCARD];
                $wildcardParams = $params;
                $wildcardSegIdx = $idx;
            }

            if (($next = $currentNode[self::NODE_STATIC][$segment] ?? null) !== null) {
                $lastStaticNode = $currentNode;
                $lastStaticSegment = $segment;
                $currentNode = $next;
                continue;
            }

            if ($segment !== '' && ($next = $currentNode[self::NODE_PARAM]) !== null) {
                $currentNode = $next;
                $params[] = $segment;
                continue;
            }
            goto NO_MATCH;
        }

        $routes = $currentNode[self::NODE_ROUTES];
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            goto HANDLE_405;
        }

        $routes = $lastStaticNode[self::NODE_PARAM][self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $params[] = $lastStaticSegment;
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            goto HANDLE_405;
        }

        $routes = $currentNode[self::NODE_WILDCARD][self::NODE_ROUTES] ?? null;
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
            $routes = $wildcardNode[self::NODE_ROUTES] ?? null;
            if (isset($routes)) {
                $wildcardParams[] = \implode('/', \array_slice($segments, $wildcardSegIdx));
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
