<?php

declare(strict_types=1);

namespace Wilaak\Http;

/**
 * RadixRouter (or RadXRouter) HTTP request router for PHP.
 *
 * @license MIT
 * @link https://github.com/wilaak/radix-router
 */
class RadixRouter
{
    /**
     * Dynamic routes
     * 
     * WARNING: Structure might change in a future stable release.
     * Do not rely on the internal format of this property without locking your version first.
     */
    public array $tree = self::TREE_NODE;

    /**
     * Static routes
     * 
     * WARNING: Structure might change in a future stable release.
     * Do not rely on the internal format of this property without locking your version first.
     */
    public array $static = [];

    /**
     * List of allowed HTTP methods during registration.
     * 
     * NOTE: Methods added must be uppercase.
     */
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD', 'QUERY'];

    private const TREE_LITERAL  = 0; // literal segment => node
    private const TREE_PARAM    = 1; // /:path
    private const TREE_WILDCARD = 2; // /:path+, /:path* uses this and TREE_EPSILON
    private const TREE_ACCEPT   = 3; // method => result, pattern ends here
    private const TREE_EPSILON  = 4; // method => result, /:path* matched nothing

    private const TREE_NODE = [
        self::TREE_LITERAL  => null,
        self::TREE_PARAM    => null,
        self::TREE_WILDCARD => null,
        self::TREE_ACCEPT   => null,
        self::TREE_EPSILON  => null,
    ];

    /**
     * Forks deeper than this are never retried but most route trees never get there.
     * Just be warned!!
     */
    private const BACKTRACK_DEPTH_MAX = \PHP_INT_SIZE * 4;

    /**
     * Add a route for one or more HTTP methods and a given pattern.
     *
     * @param string|list<string> $methods HTTP methods (e.g., ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param mixed $handler Handler to associate with the route.
     *
     * @throws \InvalidArgumentException On invalid method, pattern, or route conflict.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (!\str_starts_with($pattern, '/')) {
            $pattern = "/{$pattern}";
        }
        if ($methods === []) {
            throw new \InvalidArgumentException(
                "Invalid HTTP Method: Got empty array for pattern '{$pattern}'"
            );
        }

        $validMethods = [];
        $routePaths = [];

        foreach ((array) $methods as $method) {
            $method = \strtoupper($method);
            $isAllowedMethod = $method === '*' || \in_array($method, $this->allowedMethods, true);
            if (!$isAllowedMethod) {
                throw new \InvalidArgumentException(
                    "Invalid HTTP Method: [{$method}] '{$pattern}': Allowed methods: " . \implode(', ', $this->allowedMethods)
                );
            }

            if ($routePaths === []) {
                $routePaths = $this->expandPattern($method, $pattern);
            }

            $isDuplicateMethod = \in_array($method, $validMethods, true);
            if ($isDuplicateMethod) {
                throw new \InvalidArgumentException(
                    "Route Conflict: [{$method}] '{$pattern}': Path is already registered"
                );
            }

            foreach ($routePaths as $routePath) {
                $registeredRoutes = $this->registeredRoutes($routePath);
                $isPathTaken = isset($registeredRoutes[$method]);
                if (!$isPathTaken) {
                    continue;
                }

                $conflictingPattern = $registeredRoutes[$method]['pattern'];
                $isSamePattern = $conflictingPattern === $pattern;
                $conflictDetail = $isSamePattern ? '' : " (conflicts with '{$conflictingPattern}')";
                throw new \InvalidArgumentException(
                    "Route Conflict: [{$method}] '{$pattern}': Path is already registered{$conflictDetail}"
                );
            }

            $validMethods[] = $method;
        }

        foreach ($routePaths as $routePath) {
            $route = [
                'code' => 200,
                'handler' => $handler,
                'params' => [],
                'pattern' => $pattern
            ];

            $isStaticPath = \is_string($routePath);
            if ($isStaticPath) {
                foreach ($validMethods as $method) {
                    $this->static[$routePath][$method] = $route;
                }
                continue;
            }

            $node = &$this->tree;
            $targetSlot = self::TREE_ACCEPT;
            foreach ($routePath as $i => [$kind, $name]) {
                if ($kind === self::TREE_LITERAL) {
                    $node = &$node[self::TREE_LITERAL][$name];
                    $node ??= self::TREE_NODE;
                    continue;
                }

                $isWildcard = $kind !== self::TREE_PARAM;
                $route['params'][$name] = $isWildcard ? ~$i : $i;

                $matchesZeroSegments = $kind === self::TREE_EPSILON;
                if ($matchesZeroSegments) {
                    $targetSlot = self::TREE_EPSILON;
                    break;
                }
                $node = &$node[$kind];
                $node ??= self::TREE_NODE;
            }

            foreach ($validMethods as $method) {
                $node[$targetSlot][$method] = $route;
            }
        }
        return $this;
    }

    private function expandPattern(string $method, string $pattern): array
    {
        if (\str_contains($pattern, '//')) {
            throw new \InvalidArgumentException(
                "Invalid Pattern: [{$method}] '{$pattern}': Empty segments are not allowed (e.g., '//')"
            );
        }
        $trimmedPattern = \rtrim($pattern, '/');

        $hasNoParams = !\str_contains($pattern, '/:');
        if ($hasNoParams) {
            return [$trimmedPattern];
        }

        $segments = \explode('/', \substr($trimmedPattern, 1));
        $routePaths = [];
        $paramNames = [];
        $steps = [];

        foreach ($segments as $i => $segment) {
            $isParam = \str_starts_with($segment, ':');
            $isOptional = $isParam && \str_ends_with($segment, '?');
            $isWildcard = $isParam && (
                \str_ends_with($segment, '*') || \str_ends_with($segment, '+')
            );
            $matchesZeroSegments = $isParam && \str_ends_with($segment, '*');

            $followsOptional = $routePaths !== [];
            if ($followsOptional && !$isOptional) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Optional parameters are only allowed in the last trailing segments"
                );
            }

            if ($isOptional) {
                $prefixHasParams = $paramNames !== [];
                if ($prefixHasParams) {
                    $routePaths[] = $steps;
                } else {
                    $prefixPattern = '/' . \implode('/', \array_slice($segments, 0, $i));
                    $routePaths[] = \rtrim($prefixPattern, '/');
                }
            }
            if (!$isParam) {
                $steps[] = [self::TREE_LITERAL, $segment];
                continue;
            }

            $hasModifier = $isOptional || $isWildcard;
            $paramName = $hasModifier ? \substr($segment, 1, -1) : \substr($segment, 1);
            if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $paramName)) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': "
                        . "Parameter name '{$paramName}' must start with a letter or underscore and contain only letters, digits, or underscores"
                );
            }
            if (isset($paramNames[$paramName])) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Parameter name '{$paramName}' cannot be used more than once"
                );
            }
            $isLast = $i === \count($segments) - 1;
            if ($isWildcard && !$isLast) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Wildcard parameters are only allowed as the last segment"
                );
            }
            $paramNames[$paramName] = true;
            if ($matchesZeroSegments) {
                $routePaths[] = [...$steps, [self::TREE_EPSILON, $paramName]];
            }
            $steps[] = [$isWildcard ? self::TREE_WILDCARD : self::TREE_PARAM, $paramName];
        }
        $routePaths[] = $steps;
        return $routePaths;
    }

    private function registeredRoutes(string|array $routePath): array
    {
        $isStaticPath = \is_string($routePath);
        if ($isStaticPath) {
            return $this->static[$routePath] ?? [];
        }

        $node = $this->tree;
        foreach ($routePath as [$kind, $name]) {
            $matchesZeroSegments = $kind === self::TREE_EPSILON;
            if ($matchesZeroSegments) {
                return $node[self::TREE_EPSILON] ?? [];
            }

            if ($kind === self::TREE_LITERAL) {
                $node = $node[self::TREE_LITERAL][$name] ?? null;
            } else {
                $node = $node[$kind];
            }

            $isMissing = $node === null;
            if ($isMissing) {
                return [];
            }
        }
        return $node[self::TREE_ACCEPT] ?? [];
    }

    /**
     * List all registered routes, optionally filtered by a request path.
     *
     * @param string|null $path If provided, lists only routes matching this path.
     * @return list<array{method: string, pattern: string, handler: mixed}>
     */
    public function list(?string $path = null): array
    {
        if ($path !== null) {
            $result = $this->lookup('*', $path);
            if ($result['code'] !== 405) {
                return [];
            }
            $routes = [];
            foreach ($result['_routes'] as $method => $route) {
                $routes[] = [
                    'method'  => $method,
                    'pattern' => $route['pattern'],
                    'handler' => $route['handler'],
                ];
            }
            return $routes;
        }

        $buckets = \array_values($this->static);

        $queue = [$this->tree];
        for ($i = 0; $i < \count($queue); $i++) {
            $node = $queue[$i];
            if ($node[self::TREE_ACCEPT] !== null) {
                $buckets[] = $node[self::TREE_ACCEPT];
            }
            foreach (($node[self::TREE_LITERAL] ?? []) as $child) {
                $queue[] = $child;
            }
            foreach ([self::TREE_WILDCARD, self::TREE_PARAM] as $kind) {
                if ($node[$kind] !== null) {
                    $queue[] = $node[$kind];
                }
            }
        }

        $routes = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $method => $route) {
                $routes[] = [
                    'method'  => $method,
                    'pattern' => $route['pattern'],
                    'handler' => $route['handler'],
                ];
            }
        }

        $routes = \array_values(\array_unique($routes, \SORT_REGULAR));
        \usort($routes, fn(array $a, array $b): int => [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']]);
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

        $matchedRoutes = null;
        $segments = null;

        $bucket = $this->static[$path] ?? null;
        if (isset($bucket)) {
            goto DISPATCH;
        }

        TREE:
        $segments = $path !== '' ? \explode('/', \substr($path, 1)) : [];
        $segmentCount = \count($segments);
        $wildcardPass = false;
        $backtrackMask = 0;

        WALK:
        $node = $this->tree;
        $backtrackDepth = 0;
        for ($i = 0; $i < $segmentCount; $i++) {
            $segment = $segments[$i];
            $wildcardNode = $wildcardPass ? $node[self::TREE_WILDCARD] : null;
            $backtrackBranch = 0;

            if (($child = $node[self::TREE_LITERAL][$segment] ?? null) !== null) {
                if ($node[self::TREE_PARAM] === null && $wildcardNode === null) {
                    $node = $child;
                    continue;
                }
                $backtrackBranch = ($backtrackMask >> ($backtrackDepth++ << 1)) & 3;
                if ($backtrackBranch === 0) {
                    $node = $child;
                    continue;
                }
            } elseif ($wildcardNode !== null && $node[self::TREE_PARAM] !== null) {
                $backtrackBranch = (($backtrackMask >> ($backtrackDepth++ << 1)) & 3) + 1;
            }

            if ($backtrackBranch <= 1 && $segment !== '' && ($child = $node[self::TREE_PARAM]) !== null) {
                $node = $child;
                continue;
            }
            if ($wildcardNode !== null) {
                $bucket = $wildcardNode[self::TREE_ACCEPT];
                goto DISPATCH;
            }
            goto BACKTRACK;
        }

        $bucket = $node[$wildcardPass ? self::TREE_EPSILON : self::TREE_ACCEPT];
        if ($bucket !== null) {
            goto DISPATCH;
        }

        BACKTRACK:
        while ($backtrackDepth-- > 0) {
            if ($backtrackDepth >= self::BACKTRACK_DEPTH_MAX) {
                continue;
            }
            $shift = $backtrackDepth << 1;
            $backtrackBranch = ($backtrackMask >> $shift) & 3;
            if ($backtrackBranch < 2) {
                $backtrackMask = ($backtrackMask & ((1 << $shift) - 1)) | (($backtrackBranch + 1) << $shift);
                goto WALK;
            }
        }
        if (!$wildcardPass) {
            $wildcardPass = true;
            $backtrackMask = 0;
            goto WALK;
        }
        if ($matchedRoutes === null) {
            return ['code' => 404];
        }

        $matchedMethods = \array_keys($matchedRoutes);
        if (isset($matchedRoutes['GET']) && !isset($matchedRoutes['HEAD'])) {
            $matchedMethods[] = 'HEAD';
        }
        return ['code' => 405, 'allowed_methods' => $matchedMethods, '_routes' => $matchedRoutes];

        DISPATCH:
        $route = $bucket[$method] ?? null;
        if ($route === null && $method === 'HEAD') {
            $route = $bucket['GET'] ?? null;
        }
        $route ??= $bucket['*'] ?? null;

        if (isset($route) && $method !== '*') {
            foreach ($route['params'] as $name => $segmentIndex) {
                $route['params'][$name] = $segmentIndex >= 0
                    ? $segments[$segmentIndex]
                    : \implode('/', \array_slice($segments, ~$segmentIndex));
            }
            return $route;
        }

        $matchedRoutes = ($matchedRoutes ?? []) + $bucket;
        if ($segments === null) {
            goto TREE;
        }
        goto BACKTRACK;
    }
}
