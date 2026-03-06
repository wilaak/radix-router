<?php

declare(strict_types=1);

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * High-performance radix tree based HTTP request router
 *
 * @license WTFPL-2
 * @link https://github.com/wilaak/radix-router
 */
class RadixRouter
{
    public array $tree = [];
    public array $static = [];

    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    private const NODE_WILDCARD = '/w';
    private const NODE_PARAMETER = '/p';
    private const NODE_ROUTES = '/r';

    private ?string $optionalPattern = null;

    /**
     * Registers a route for one or more HTTP methods.
     *
     * @param string|array<int, string> $methods HTTP methods (e.g., ['GET', 'POST']).
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
                'pattern' => $this->optionalPattern ?? $pattern,
                'params' => [],
            ];
            return $this;
        }

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
                $segment = self::NODE_WILDCARD;
            } else {
                $segment = self::NODE_PARAMETER;
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

        if (isset($currentNode[self::NODE_ROUTES][$method])) {
            $attempted = $this->optionalPattern ?? $pattern;
            $conflicting = $currentNode[self::NODE_ROUTES][$method]['pattern'];
            throw new InvalidArgumentException(
                "Route Conflict: [{$method}] '{$attempted}': Path is already registered"
                    . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')" : '')
            );
        }
        $currentNode[self::NODE_ROUTES][$method] = [
            'code' => 200,
            'handler' => $handler,
            'params' => $params,
            'pattern' => $this->optionalPattern ?? $pattern,
        ];
        return $this;
    }

    /**
     * List all routes or routes matching a specific path.
     *
     * @param string|null $path If provided, lists only routes matching this path.
     * @return list<array{method: string, pattern: string, handler: mixed}>
     */
    public function list(?string $path = null): array
    {
        $formatRoute = fn (string $method, array $route): array => [
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
            if (isset($currentNode[self::NODE_ROUTES])) {
                foreach ($currentNode[self::NODE_ROUTES] as $method => $route) {
                    $routes[] = $formatRoute($method, $route);
                }
            }
            foreach ($currentNode as $segment => $child) {
                if (\str_starts_with($segment, '/')) {
                    continue;
                }
                $collect($child);
            }
            if (isset($currentNode[self::NODE_WILDCARD])) {
                $collect($currentNode[self::NODE_WILDCARD]);
            }
            if (isset($currentNode[self::NODE_PARAMETER])) {
                $collect($currentNode[self::NODE_PARAMETER]);
            }
        };
        $collect($this->tree);

        $routes = \array_values(\array_unique($routes, SORT_REGULAR));
        \usort($routes, fn ($a, $b): int => $a['pattern'] <=> $b['pattern'] ?: $a['method'] <=> $b['method']);

        return $routes;
    }

    /**
     * List allowed HTTP methods for a given path.
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
     * Retrieve route for a given HTTP method and path.
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
        $path = \rtrim($path, '/');
        $result = $this->matchRoute($method, $path);
        if ($result['code'] !== 405) {
            return $result;
        }
        $routes = $result['_routes'];
        if (isset($routes['GET']) && !isset($routes['HEAD'])) {
            if ($method === 'HEAD') {
                return $routes['GET'];
            }
            $result['allowed_methods'][] = 'HEAD';
        }
        return $result;
    }

    private function matchRoute(string $method, string $path): array
    {
        $routes = $this->static[$path] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        $segments = \explode('/', $path);
        $params = [];
        $currentNode = $this->tree;

        foreach ($segments as $i => $segment) {
            $lastStaticNode = null;

            if (isset($currentNode[self::NODE_WILDCARD])) {
                $wildcardNode = $currentNode[self::NODE_WILDCARD];
                $wildcardParams = $params;
                $wildcardOffset = $i;
            }

            if (isset($currentNode[$segment])) {
                $lastStaticNode = $currentNode;
                $lastStaticSegment = $segment;
                $currentNode = $currentNode[$segment];
                continue;
            }

            if ($segment !== '' && isset($currentNode[self::NODE_PARAMETER])) {
                $currentNode = $currentNode[self::NODE_PARAMETER];
                $params[] = $segment;
                continue;
            }
            unset($currentNode);
            break;
        }

        $routes = $currentNode[self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        $routes = $lastStaticNode[self::NODE_PARAMETER][self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $params[] = $lastStaticSegment;
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        $routes = $currentNode[self::NODE_WILDCARD][self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $pattern = $result['pattern'];
                if (\str_ends_with($pattern, '*') || \str_ends_with($pattern, '*/')) {
                    $params[] = '';
                    $result['params'] = \array_combine($result['params'], $params);
                    return $result;
                }
            }

            $optionalWildcards = \array_filter($routes, function ($result) {
                $pattern = $result['pattern'];
                return \str_ends_with($pattern, '*') || \str_ends_with($pattern, '*/');
            });

            if (!empty($optionalWildcards)) {
                $allowedMethods = \array_keys($optionalWildcards);
                return ['code' => 405, 'allowed_methods' => $allowedMethods, '_routes' => $optionalWildcards];
            }
        }

        $routes = $wildcardNode[self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? $routes['*'] ?? null;
            if (isset($result) && $method !== '*') {
                $params = \array_merge(
                    $wildcardParams,
                    [\implode('/', \array_slice($segments, $wildcardOffset))]
                );
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        return ['code' => 404];
    }

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
