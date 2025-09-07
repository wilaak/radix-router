<?php

declare(strict_types=1);

namespace Wilaak\Http;

use InvalidArgumentException;

/**
 * High-performance HTTP request router for PHP
 *
 * @license WTFPL-2.0
 * @link    https://github.com/Wilaak/RadixRouter
 */
class RadixRouter
{
    /**
     * Radix tree structure for routes with parameters.
     */
    public array $tree = [];

    /**
     * Static routes for quicker lookups with no parameters.
     */
    public array $static = [];

    /**
     * List of allowed HTTP methods. When adding routes only these methods are accepted.
     */
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Node keys for the radix tree, prefixed with a forward slash to avoid collisions with path segments.
     */
    private const string NODE_WILDCARD = '/wildcard_node';
    private const string NODE_PARAMETER = '/parameter_node';
    private const string NODE_ROUTES = '/routes_node';

    /**
     * When adding optional parameter variants we use this to keep track of the original pattern.
     */
    private ?string $originalOptionalPattern = null;

    /**
     * Adds a route for one or more HTTP methods and a given pattern.
     *
     * @param string|array<int, string> $methods HTTP methods (e.g., ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param mixed $handler Handler to associate with the route.
     *
     * @throws InvalidArgumentException On invalid method, route pattern or conflicts.
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (\is_string($methods)) {
            $methods = [$methods];
        }

        if (empty($methods)) {
            throw new InvalidArgumentException(
                "At least one HTTP method must be specified for route pattern '{$pattern}'."
            );
        }

        foreach ($methods as &$method) {
            if (!\is_string($method) || $method === '') {
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
                . "Example: '/users/:id'."
            );
        }

        $normalizedPattern = \rtrim($pattern, '/');
        $segments = \explode('/', $normalizedPattern);
        $paramNames = [];
        foreach ($segments as $i => &$segment) {
            if (!\str_starts_with($segment, ':')) {
                continue;
            }

            if (\str_ends_with($segment, '*?') || \str_ends_with($segment, '?*')) {
                throw new InvalidArgumentException(
                    "Malformed route pattern '{$pattern}': "
                    . "Parameters cannot be both optional ('?') and wildcard ('*'). "
                    . "Use either ':param?' or ':param*', not both."
                );
            }

            if (\str_ends_with($segment, '?')) {
                $this->originalOptionalPattern = $pattern;
                $variants = $this->getOptionalParameterVariants($normalizedPattern);
                foreach ($variants as $variant) {
                    $this->add($methods, $variant, $handler);
                }
                $this->originalOptionalPattern = null;
                return $this;
            }

            $paramName = \substr($segment, 1);
            if (\str_ends_with($paramName, '*')) {
                $paramName = \substr($paramName, 0, -1);
            }
            // use ctype functions if available for better performance
            if (\extension_loaded('ctype')) {
                $isParamNameInvalid = (
                    $paramName === ''
                    || (!\ctype_alpha($paramName[0]) && $paramName[0] !== '_')
                    || !\ctype_alnum(\str_replace('_', '', $paramName))
                );
            } else {
                $isParamNameInvalid = (
                    $paramName === ''
                    || !\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $paramName)
                );
            }
            if ($isParamNameInvalid) {
                throw new InvalidArgumentException(
                    "Invalid parameter name '{$paramName}' in route pattern '{$pattern}': "
                    . "Parameter names must start with a letter or underscore, contain only alphanumeric characters or underscores, and cannot be empty (e.g., ':user_id')."
                );
            }
            if (\in_array($paramName, $paramNames, true)) {
                throw new InvalidArgumentException(
                    "Duplicate parameter name '{$paramName}' in route pattern '{$pattern}': "
                    . "Each parameter name must be unique within a route pattern."
                );
            }
            $paramNames[] = $paramName;

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

        if (!empty($paramNames)) {
            $node = &$this->tree;
            foreach ($segments as $segment) {
                $node = &$node[$segment];
            }
            foreach ($methods as $method) {
                if (isset($node[self::NODE_ROUTES][$method])) {
                    $currentPattern = $this->originalOptionalPattern ?? $pattern;
                    $previousPattern = $node[self::NODE_ROUTES][$method]['orig_pattern'];
                    throw new InvalidArgumentException(
                        "Cannot add route '[{$method}] {$currentPattern}': this route is already registered at '[{$method}] {$previousPattern}'."
                    );
                }
                $node[self::NODE_ROUTES][$method] = [
                    'handler' => $handler,
                    'param_names' => $paramNames,
                    'orig_pattern' => $this->originalOptionalPattern ?? $pattern,
                ];
            }
        } else {
            foreach ($methods as $method) {
                if (isset($this->static[$normalizedPattern][$method])) {
                    $currentPattern = $this->originalOptionalPattern ?? $pattern;
                    $previousPattern = $this->static[$normalizedPattern][$method]['orig_pattern'];
                    throw new InvalidArgumentException(
                        "Cannot add route '[{$method}] {$currentPattern}': this route is already registered at '[{$method}] {$previousPattern}'."
                    );
                }
                $this->static[$normalizedPattern][$method] = [
                    'handler' => $handler,
                    'orig_pattern' => $this->originalOptionalPattern ?? $pattern,
                ];
            }
        }

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
        $normalizedPath = \rtrim($path, '/');

        if (isset($this->static[$normalizedPath])) {
            $route = $this->static[$normalizedPath][$method] ?? null;
            if ($route !== null) {
                return [
                    'code' => 200,
                    'handler' => $route['handler'],
                    'params' => [],
                ];
            }
            return [
                'code' => 405,
                'allowed_methods' => \array_keys($this->static[$normalizedPath]),
            ];
        }

        $segments = \explode('/', $normalizedPath);
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
            } elseif ($segment !== '' && isset($node[self::NODE_PARAMETER])) {
                $node = $node[self::NODE_PARAMETER];
                $params[] = $segment;
            } else {
                unset($node);
                break;
            }
        }

        if (isset($node[self::NODE_ROUTES])) {
            $route = $node[self::NODE_ROUTES][$method] ?? null;
            if ($route !== null) {
                return [
                    'code' => 200,
                    'handler' => $route['handler'],
                    'params' => \array_combine($route['param_names'], $params),
                ];
            }
            return [
                'code' => 405,
                'allowed_methods' => \array_keys($node[self::NODE_ROUTES]),
            ];
        }

        if (isset($node[self::NODE_WILDCARD])) {
            $wildcardNode = $node[self::NODE_WILDCARD];
            $wildcardParams = $params;
            $wildcardIndex = $i + 1;
        }

        if (isset($wildcardNode[self::NODE_ROUTES])) {
            $route = $wildcardNode[self::NODE_ROUTES][$method] ?? null;
            if ($route !== null) {
                $params = \array_merge(
                    $wildcardParams,
                    [\implode('/', \array_slice($segments, $wildcardIndex))]
                );
                return [
                    'code' => 200,
                    'handler' => $route['handler'],
                    'params' => \array_combine($route['param_names'], $params),
                ];
            }
            return [
                'code' => 405,
                'allowed_methods' => \array_keys($wildcardNode[self::NODE_ROUTES]),
            ];
        }

        return ['code' => 404];
    }

    /**
     * Generates all route pattern variants for optional parameters.
     *
     * For example, '/users/:id?/:action?' produces:
     *   ['/users', '/users/:id', '/users/:id/:action']
     *
     * @return array<int, string>
     *
     * @throws InvalidArgumentException If optional parameters are not in trailing segments.
     */
    private function getOptionalParameterVariants(string $pattern): array
    {
        $segments = \explode('/', $pattern);
        $variants = [];
        $currentSegments = [];
        $optionalFound = false;

        foreach ($segments as $segment) {
            $isOptional = \str_ends_with($segment, '?');

            if ($isOptional) {
                $optionalFound = true;
                $variant = \implode('/', $currentSegments);
                $variants[] = $variant === '' ? '/' : $variant;
                $currentSegments[] = \rtrim($segment, '?');
            } else {
                if ($optionalFound) {
                    throw new InvalidArgumentException(
                        "Invalid route pattern '{$pattern}': "
                        . "Optional parameters (e.g., ':param?') must only appear "
                        . 'in the last trailing segments of the route pattern.'
                    );
                }
                $currentSegments[] = $segment;
            }
        }

        $variants[] = \implode('/', $currentSegments);

        return $variants;
    }
}
