<?php

declare(strict_types=1);

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * High-performance radix tree based HTTP request router
 *
 * @license WTFPL-2
 * @link    https://github.com/Wilaak/RadixRouter
 */
class RadixRouter
{
    public array $tree = [];
    public array $static = [];
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Node types in the routing tree.
     */
    private const NODE_WILDCARD = '/w';
    private const NODE_PARAMETER = '/p';
    private const NODE_ROUTES = '/r';

    /**
     * Tracks the original pattern when expanding optional parameters.
     */
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
        if (\is_array($methods)) {
            if (empty($methods)) {
                throw new InvalidArgumentException(
                    "Invalid HTTP Method: Got empty array for pattern '{$pattern}'."
                );
            }
            foreach ($methods as $method) {
                $this->add($method, $pattern, $handler);
            }
            return $this;
        }

        $method = \strtoupper($methods);

        if (!\in_array($method, $this->allowedMethods, true)) {
            throw new InvalidArgumentException(
                "Invalid HTTP Method: [{$method}] '{$pattern}': Allowed methods: " . \implode(', ', $this->allowedMethods) . '.'
            );
        }
        if (!\str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException(
                "Invalid Pattern: [{$method}] '{$pattern}': Must start with a forward slash (e.g., '/about')."
            );
        }
        if (\str_contains($pattern, '//')) {
            throw new InvalidArgumentException(
                "Invalid Pattern: [{$method}] '{$pattern}': Empty segments are not allowed (e.g., '//')."
            );
        }

        $normalizedPattern = \rtrim($pattern, '/');
        $isDynamic = \str_contains($pattern, '/:');

        if (!$isDynamic) {
            if (isset($this->static[$normalizedPattern][$method])) {
                $attempted = $this->optionalPattern ?? $pattern;
                $conflicting = $this->static[$normalizedPattern][$method]['pattern'];
                throw new InvalidArgumentException(
                    "Route Conflict: [{$method}] '{$attempted}': Path is already registered."
                    . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')." : '')
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
        $parameters = [];

        foreach ($segments as $i => &$segment) {
            if ($isOptional && !\str_ends_with($segment, '?')) {
                throw new InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Optional parameters are only allowed in the last trailing segments."
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
                    . "Parameter name '{$name}' must start with a letter or underscore and contain only letters, digits, or underscores."
                );
            }
            if (isset($parameters[$name])) {
                throw new InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Parameter name '{$name}' cannot be used more than once."
                );
            }
            $parameters[$name] = $name;

            if (\str_ends_with($segment, '?')) {
                $isOptional = true;
            }

            if (\str_ends_with($segment, '*') || \str_ends_with($segment, '+')) {
                if ($i !== \array_key_last($segments)) {
                    throw new InvalidArgumentException(
                        "Invalid Pattern: [{$method}] '{$pattern}': Wildcard parameters are only allowed as the last segment."
                    );
                }
                $segment = self::NODE_WILDCARD;
            } else {
                $segment = self::NODE_PARAMETER;
            }
        }
        unset($segment);

        if ($isOptional) {
            $variants = $this->getOptionalVariants($pattern);
            $this->optionalPattern = $pattern;
            foreach ($variants as $variant) {
                $this->add($method, $variant, $handler);
            }
            $this->optionalPattern = null;
            return $this;
        }

        $node = &$this->tree;
        foreach ($segments as $segment) {
            $node = &$node[$segment];
        }

        if (isset($node[self::NODE_ROUTES][$method])) {
            $attempted = $this->optionalPattern ?? $pattern;
            $conflicting = $node[self::NODE_ROUTES][$method]['pattern'];
            throw new InvalidArgumentException(
                "Route Conflict: [{$method}] '{$attempted}': Path is already registered."
                . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')." : '')
            );
        }
        $node[self::NODE_ROUTES][$method] = [
            'code' => 200,
            'handler' => $handler,
            'params' => $parameters,
            'pattern' => $this->optionalPattern ?? $pattern,
        ];
        return $this;
    }

    /**
     * Looks up a route based on the HTTP method and path.
     *
     * @param string $method HTTP method (e.g., 'GET', 'POST').
     * @param string $path Request path (e.g., '/users/123').
     * @return array{code: int, handler?: mixed, params?: array<string, string>, pattern?: string, allowed_methods?: list<string>}
     */
    public function lookup(string $method, string $path): array
    {
        $path = \rtrim($path, '/');

        $routes = $this->static[$path] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? null;
            if (isset($result)) {
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        $segments = \explode('/', $path);
        $params = [];
        $node = $this->tree;
        $wildcardNode = $wildcardParams = $wildcardOffset = null;

        foreach ($segments as $i => $segment) {
            if (isset($node[self::NODE_WILDCARD])) {
                $wildcardNode = $node[self::NODE_WILDCARD];
                $wildcardParams = $params;
                $wildcardOffset = $i;
            }
            if (isset($node[$segment])) {
                $node = $node[$segment];
            } elseif ($segment !== '' && isset($node[self::NODE_PARAMETER])) {
                $node = $node[self::NODE_PARAMETER];
                $params[] = $segment;
            } else {
                unset($node);
                break;
            }
        }

        $routes = $node[self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? null;
            if (isset($result)) {
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        $routes = $node[self::NODE_WILDCARD][self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? null;
            if (isset($result)) {
                $pattern = $result['pattern'];
                $isOptional = \str_ends_with($pattern, '*') || \str_ends_with($pattern, '*/');
                if (!$isOptional) {
                    goto required_wildcard_match; // max win ^_^
                }
                $params[] = '';
                $result['params'] = \array_combine($result['params'], $params);
                return $result;
            }
            return ['code' => 405, 'allowed_methods' => \array_keys($routes), '_routes' => $routes];
        }

        required_wildcard_match: // 😏
        $routes = $wildcardNode[self::NODE_ROUTES] ?? null;
        if (isset($routes)) {
            $result = $routes[$method] ?? null;
            if (isset($result)) {
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
            $result = $this->lookup('ANALPROBE', $path);
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

        $collect = function ($node) use (&$collect, &$routes, $formatRoute): void {
            if (isset($node[self::NODE_ROUTES])) {
                foreach ($node[self::NODE_ROUTES] as $method => $route) {
                    $routes[] = $formatRoute($method, $route);
                }
            }
            foreach ($node as $segment => $child) {
                if (\str_starts_with($segment, '/')) {
                    continue;
                }
                $collect($child);
            }
            if (isset($node[self::NODE_WILDCARD])) {
                $collect($node[self::NODE_WILDCARD]);
            }
            if (isset($node[self::NODE_PARAMETER])) {
                $collect($node[self::NODE_PARAMETER]);
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
        return $this->lookup('ANALPROBE', $path)['allowed_methods'] ?? [];
    }

    /**
     * Generates all variants of a route pattern with optional parameters.
     *
     * Example:
     *   '/users/:id?/:action?' turns into ['/users', '/users/:id', '/users/:id/:action']
     *
     * @param string $pattern Route pattern with optional parameters.
     * @return list<string> List of route pattern variants.
     */
    private function getOptionalVariants(string $pattern): array
    {
        $segments = \explode('/', \trim($pattern, '/'));
        $firstOptional = null;
        $count = \count($segments);
        for ($i = 0; $i < $count; $i++) {
            if (\str_ends_with($segments[$i], '?')) {
                $firstOptional = $i;
                break;
            }
        }
        $variants = [];
        for ($variantEnd = $firstOptional; $variantEnd <= $count; $variantEnd++) {
            $parts = [];
            foreach (\array_slice($segments, 0, $variantEnd) as $segment) {
                $parts[] = \rtrim($segment, '?');
            }
            $variants[] = '/' . \implode('/', $parts);
        }
        return $variants;
    }
}
