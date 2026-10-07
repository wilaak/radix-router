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
    public array $tree = self::NODE_STRUCT;

    /**
     * Static routes
     * 
     * WARNING: Structure might change in a future stable release.
     * Do not rely on the internal format of this property without locking your version first.
     */
    public array $static = [];

    /**
     * List of allowed HTTP methods during registration.
     */
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD', 'QUERY'];

    /**
     * Used to track the original pattern during registration of optional parameter variants.
     */
    private ?string $optionalPattern = null;

    private const NODE_STATIC   = 0; // ('/path')
    private const NODE_PARAM    = 1; // ('/:path')
    private const NODE_WILDCARD = 2; // ('/:path+')
    private const NODE_OPTIONAL = 3; // ('/:path*')
    private const NODE_ROUTES   = 4; // Method->Result->Handler

    private const NODE_STRUCT = [
        self::NODE_STATIC   => null,
        self::NODE_PARAM    => null,
        self::NODE_WILDCARD => null,
        self::NODE_OPTIONAL => null,
        self::NODE_ROUTES   => null,
    ];

    private const MAX_FORKS = \PHP_INT_SIZE * 4;

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

        if (\is_array($methods)) {
            if (empty($methods)) {
                throw new \InvalidArgumentException(
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
            throw new \InvalidArgumentException(
                "Invalid HTTP Method: [{$method}] '{$pattern}': Allowed methods: " . \implode(', ', $this->allowedMethods)
            );
        }
        if (\str_contains($pattern, '//')) {
            throw new \InvalidArgumentException(
                "Invalid Pattern: [{$method}] '{$pattern}': Empty segments are not allowed (e.g., '//')"
            );
        }

        $key = \rtrim($pattern, '/');

        if (!\str_contains($pattern, '/:')) {
            $this->static[$key] ??= [];
            $this->addBindRouteToBucket($this->static[$key], $method, $pattern, [], $handler);
            return $this;
        }

        [$steps, $params, $isOptional] = $this->addCompileSegments($method, $pattern, $key);

        if ($isOptional) {
            [$staticSnapshot, $treeSnapshot] = [$this->static, $this->tree];
            $this->optionalPattern = $pattern;
            try {
                foreach ($this->addExpandOptionalSegments($key) as $variant) {
                    $this->add($method, $variant, $handler);
                }
            } catch (\InvalidArgumentException $e) {
                [$this->static, $this->tree] = [$staticSnapshot, $treeSnapshot];
                throw $e;
            } finally {
                $this->optionalPattern = null;
            }
            return $this;
        }

        $node = &$this->tree;
        foreach ($steps as [$kind, $literal]) {
            $parent = &$node;
            if ($kind === self::NODE_STATIC) {
                $node[self::NODE_STATIC][$literal] ??= self::NODE_STRUCT;
                $node = &$node[self::NODE_STATIC][$literal];
            } else {
                $node[$kind] ??= self::NODE_STRUCT;
                $node = &$node[$kind];
            }
        }

        $node[self::NODE_ROUTES] ??= [];
        $this->addBindRouteToBucket($node[self::NODE_ROUTES], $method, $pattern, $params, $handler);

        if ($kind === self::NODE_WILDCARD && \str_ends_with($key, '*')) {
            $parent[self::NODE_OPTIONAL] ??= [];
            $this->addBindRouteToBucket($parent[self::NODE_OPTIONAL], $method, $pattern, $params, $handler);
        }
        return $this;
    }

    private function addCompileSegments(string $method, string $pattern, string $key): array
    {
        $segments = \explode('/', \substr($key, 1));
        $last = \count($segments) - 1;
        $params = [];
        $steps = [];
        $seenOptional = false;

        foreach ($segments as $i => $segment) {
            if ($seenOptional && !(\str_starts_with($segment, ':') && \str_ends_with($segment, '?'))) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Optional parameters are only allowed in the last trailing segments"
                );
            }

            if (!\str_starts_with($segment, ':')) {
                $steps[] = [self::NODE_STATIC, $segment];
                continue;
            }

            $name = \substr($segment, 1);
            $kind = self::NODE_PARAM;
            $isOptional = false;

            $suffix = $segment[-1];
            if ($suffix === '?') {
                $name = \substr($name, 0, -1);
                $isOptional = true;
            } elseif ($suffix === '*' || $suffix === '+') {
                $name = \substr($name, 0, -1);
                $kind = self::NODE_WILDCARD;
            }

            if (!\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': "
                        . "Parameter name '{$name}' must start with a letter or underscore and contain only letters, digits, or underscores"
                );
            }
            if (isset($params[$name])) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Parameter name '{$name}' cannot be used more than once"
                );
            }
            if ($kind === self::NODE_WILDCARD && $i !== $last) {
                throw new \InvalidArgumentException(
                    "Invalid Pattern: [{$method}] '{$pattern}': Wildcard parameters are only allowed as the last segment"
                );
            }

            $params[$name] = $kind === self::NODE_WILDCARD ? ~$i : $i;
            if ($isOptional) {
                $seenOptional = true;
            }
            $steps[] = [$kind, null];
        }

        return [$steps, $params, $seenOptional];
    }

    private function addExpandOptionalSegments(string $key): array
    {
        $variants = [];
        $prefix = '';
        foreach (\explode('/', \substr($key, 1)) as $segment) {
            if (\str_starts_with($segment, ':') && \str_ends_with($segment, '?')) {
                $variants[] = $prefix;
                $segment = \substr($segment, 0, -1);
            }
            $prefix .= "{$segment}/";
        }
        $variants[] = $prefix;
        return $variants;
    }

    private function addBindRouteToBucket(array &$bucket, string $method, string $pattern, array $params, mixed $handler): void
    {
        if (isset($bucket[$method])) {
            $attempted = $this->optionalPattern ?? $pattern;
            $conflicting = $bucket[$method]['pattern'];
            throw new \InvalidArgumentException(
                "Route Conflict: [{$method}] '{$attempted}': Path is already registered"
                    . ($attempted !== $conflicting ? " (conflicts with '{$conflicting}')" : '')
            );
        }
        // Store everything for a simple ref-bump return during lookups.
        $bucket[$method] = [
            'code' => 200,
            'handler' => $handler,
            'params' => $params,
            'pattern' => $this->optionalPattern ?? $pattern,
        ];
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

        $buckets = [];
        foreach ($this->static as $bucket) {
            $buckets[] = $bucket;
        }

        $stack = [$this->tree];
        for ($i = 0; $i < \count($stack); $i++) {
            $node = $stack[$i];
            if ($node[self::NODE_ROUTES] !== null) {
                $buckets[] = $node[self::NODE_ROUTES];
            }
            foreach (($node[self::NODE_STATIC] ?? []) as $child) {
                $stack[] = $child;
            }
            foreach ([self::NODE_WILDCARD, self::NODE_PARAM] as $slot) {
                if ($node[$slot] !== null) {
                    $stack[] = $node[$slot];
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

        $routes = \array_values(\array_unique($routes, SORT_REGULAR));
        \usort($routes, function (array $a, array $b): int {
            return [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']];
        });
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

        $allowedRoutes = null;
        $segments = null;

        $routes = $this->static[$path] ?? null;
        if (isset($routes)) {
            goto DISPATCH;
        }

        TREE:
        $segments = $path !== '' ? \explode('/', \substr($path, 1)) : [];
        $count = \count($segments);
        $wildcardPass = false;
        $pickMask = 0;

        WALK:
        $node = $this->tree;
        $forks = 0;
        for ($i = 0; $i < $count; $i++) {
            $segment = $segments[$i];
            $wildcard = $wildcardPass ? $node[self::NODE_WILDCARD] : null;
            $pick = 0;

            if (($next = $node[self::NODE_STATIC][$segment] ?? null) !== null) {
                if ($node[self::NODE_PARAM] === null && $wildcard === null) {
                    $node = $next;
                    continue;
                }
                $pick = ($pickMask >> ($forks++ << 1)) & 3;
                if ($pick === 0) {
                    $node = $next;
                    continue;
                }
            } elseif ($wildcard !== null && $node[self::NODE_PARAM] !== null) {
                $pick = (($pickMask >> ($forks++ << 1)) & 3) + 1;
            }

            if ($pick <= 1 && $segment !== '' && ($next = $node[self::NODE_PARAM]) !== null) {
                $node = $next;
                continue;
            }
            if ($wildcard !== null) {
                $routes = $wildcard[self::NODE_ROUTES];
                goto DISPATCH;
            }
            goto BACKTRACK;
        }

        $routes = $node[$wildcardPass ? self::NODE_OPTIONAL : self::NODE_ROUTES];
        if ($routes !== null) {
            goto DISPATCH;
        }

        BACKTRACK:
        while ($forks-- > 0) {
            if ($forks >= self::MAX_FORKS) {
                continue;
            }
            $shift = $forks << 1;
            $pick = ($pickMask >> $shift) & 3;
            if ($pick < 2) {
                $pickMask = ($pickMask & ((1 << $shift) - 1)) | (($pick + 1) << $shift);
                goto WALK;
            }
        }
        if (!$wildcardPass) {
            $wildcardPass = true;
            $pickMask = 0;
            goto WALK;
        }
        if ($allowedRoutes === null) {
            return ['code' => 404];
        }

        $allowedMethods = \array_keys($allowedRoutes);
        if (isset($allowedRoutes['GET']) && !isset($allowedRoutes['HEAD'])) {
            $allowedMethods[] = 'HEAD';
        }
        return ['code' => 405, 'allowed_methods' => $allowedMethods, '_routes' => $allowedRoutes];

        DISPATCH:
        $route = $routes[$method] ?? null;
        if ($route === null && $method === 'HEAD') {
            $route = $routes['GET'] ?? null;
        }
        $route ??= $routes['*'] ?? null;

        if (isset($route) && $method !== '*') {
            foreach ($route['params'] as $name => $index) {
                $route['params'][$name] = $index >= 0
                    ? $segments[$index]
                    : \implode('/', \array_slice($segments, ~$index));
            }
            return $route;
        }

        $allowedRoutes = ($allowedRoutes ?? []) + $routes;
        if ($segments === null) {
            goto TREE;
        }
        goto BACKTRACK;
    }
}
