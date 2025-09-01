<?php

namespace Wilaak\Http;

use \InvalidArgumentException;

/**
 * High-performance HTTP request router for PHP
 *
 * @license WTFPL-2.0
 * @link    https://github.com/Wilaak/RadixRouter
 */
class RadixRouter
{
    public array $tree = [];
    public array $static = [];
    public array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];

    /**
     * Registers a route for one or more HTTP methods and a given pattern.
     *
     * @param string|array<int, string> $methods HTTP methods (e.g., 'GET' or ['GET', 'POST']).
     * @param string $pattern Route pattern (e.g., '/users/:id', '/files/:path*', '/archive/:year?/:month?').
     * @param mixed $handler Handler to associate with the route.
     * 
     * @throws InvalidArgumentException On invalid method, pattern or conflicting route definitions.
     * 
     * Example:
     * ```php
     * // Static route
     * $router->add('GET', '/', 'handler');
     *
     * // Multiple methods
     * $router->add(['GET', 'POST'], '/form', 'handler');
     *
     * // Required parameters
     * $router->add('GET', '/users/:id', 'handler');
     * // Example requests:
     * //   /users/123 -> matches (captures ["id" => "123"])
     * //   /users     -> no match
     *
     * // Optional parameters
     * $router->add('GET', '/archive/:year?/:month?', 'handler');
     * // Example requests:
     * //   /archive         -> matches
     * //   /archive/1974    -> matches (captures ["year" => "1974"])
     * //   /archive/1974/06 -> matches (captures ["year" => "1974", "month" => "06"])
     *
     * // Wildcard parameter
     * $router->add('GET', '/files/:path*', 'handler');
     * // Example requests:
     * //   /files                  -> matches (captures ["path" => ""])
     * //   /files/readme.txt       -> matches (captures ["path" => "readme.txt"])
     * //   /files/images/photo.jpg -> matches (captures ["path" => "images/photo.jpg"])
     * ```
     */
    public function add(string|array $methods, string $pattern, mixed $handler): self
    {
        if (!\str_starts_with($pattern, '/')) {
            throw new InvalidArgumentException(
                "Invalid route pattern '{$pattern}': "
                    . "Patterns must begin with a forward slash ('/'). "
                    . "Example: '/users/:id'."
            );
        }

        if (\is_string($methods)) {
            $methods = [$methods];
        }

        if (empty($methods)) {
            throw new InvalidArgumentException(
                "At least one HTTP method must be specified for route '{$pattern}'."
            );
        }

        foreach ($methods as &$method) {
            if (!\is_string($method) || $method === '') {
                throw new InvalidArgumentException(
                    "Invalid HTTP method for route '{$pattern}': "
                        . "Method must be a non-empty string such as 'GET' or 'POST'."
                );
            }
            $method = \strtoupper($method);
            if (!\in_array($method, $this->allowedMethods, true)) {
                throw new InvalidArgumentException(
                    "Unsupported HTTP method '{$method}' for route '{$pattern}'. "
                        . "Allowed methods are: "
                        . implode(', ', $this->allowedMethods) . "."
                );
            }
        }
        unset($method);

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
                foreach ($this->getOptionalParameterVariants($normalizedPattern) as $variant) {
                    try {
                        $this->add($methods, $variant, $handler);
                    } catch (InvalidArgumentException $e) {
                        throw new InvalidArgumentException(
                            "Failed to register optional parameter variant '{$variant}' "
                                . "for pattern '{$pattern}': "
                                . $e->getMessage()
                        );
                    }
                }
                return $this;
            }

            $paramName = \substr($segment, 1);
            if (\str_ends_with($paramName, '*')) {
                $paramName = \substr($paramName, 0, -1);
            }
            if (
                $paramName === '' ||
                (!\ctype_alpha($paramName[0]) && $paramName[0] !== '_') ||
                !\ctype_alnum(\str_replace('_', '', $paramName))
            ) {
                throw new InvalidArgumentException(
                    "Invalid parameter name '{$paramName}' in route pattern '{$pattern}': "
                        . "Names must start with a letter or underscore, "
                        . "contain only alphanumeric characters or underscores, "
                        . "and cannot be empty (e.g., ':user_id')."
                );
            }
            if (\in_array($paramName, $paramNames, true)) {
                throw new InvalidArgumentException(
                    "Duplicate parameter name '{$paramName}' in route pattern '{$pattern}': "
                        . "Each parameter name must be unique within a route."
                );
            }
            $paramNames[] = $paramName;

            if (\str_ends_with($segment, '*')) {
                if ($i !== \array_key_last($segments)) {
                    throw new InvalidArgumentException(
                        "Invalid route pattern '{$pattern}': "
                            . "Wildcard parameters (e.g., ':param*') must be the last segment in the route."
                    );
                }
                $segment = '/wildcard_node';
            } else {
                $segment = '/parameter_node';
            }
        }
        unset($segment);

        if (!empty($paramNames)) {
            $node = &$this->tree;
            foreach ($segments as $segment) {
                $node = &$node[$segment];
            }
            foreach ($methods as $method) {
                if (isset($node['/routes_node'][$method])) {
                    throw new InvalidArgumentException(
                        "Route conflict: "
                            . "Method '{$method}' with pattern '{$pattern}' is already registered."
                    );
                }
                $node['/routes_node'][$method] = [
                    'handler' => $handler,
                    'param_names' => $paramNames,
                ];
            }
        } else {
            foreach ($methods as $method) {
                if (isset($this->static[$normalizedPattern][$method])) {
                    throw new InvalidArgumentException(
                        "Route conflict: "
                            . "Method '{$method}' with pattern '{$pattern}' is already registered."
                    );
                }
                $this->static[$normalizedPattern][$method] = $handler;
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
     * 
     * Example:
     * ```php
     * $router = new \Wilaak\Http\RadixRouter();
     *
     * $router->add('GET', '/:world?', function ($world = 'World') {
     *     echo "Hello, $world!";
     * });
     *
     * $method = $_SERVER['REQUEST_METHOD'];
     *
     * $path = rawurldecode(
     *     strtok($_SERVER['REQUEST_URI'], '?')
     * );
     *
     * $result = $router->lookup($method, $path);
     *
     * switch ($result['code']) {
     *     case 200:
     *         $result['handler'](...$result['params']);
     *         break;
     *
     *     case 404:
     *         http_response_code(404);
     *         echo '404 Not Found';
     *         break;
     *
     *     case 405:
     *         header('Allow: ' . implode(',', $result['allowed_methods']));
     *         http_response_code(405);
     *         echo '405 Method Not Allowed';
     *         break;
     * }
     * ```
     */
    public function lookup(string $method, string $path): array
    {
        $normalizedPath = \rtrim($path, '/');

        if (isset($this->static[$normalizedPath])) {
            if (isset($this->static[$normalizedPath][$method])) {
                return [
                    'code' => 200,
                    'handler' => $this->static[$normalizedPath][$method],
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
            $route = $node['/routes_node'][$method];
            return [
                'code' => 200,
                'handler' => $route['handler'],
                'params' => \array_combine($route['param_names'], $params),
            ];
        } else if (isset($node['/routes_node'])) {
            return [
                'code' => 405,
                'allowed_methods' => \array_keys($node['/routes_node']),
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
            $route = $wildcard['node']['/routes_node'][$method];
            $params = \array_merge(
                $wildcard['params'],
                [\implode('/', \array_slice($segments, $wildcard['index']))]
            );
            return [
                'code' => 200,
                'handler' => $route['handler'],
                'params' => \array_combine($route['param_names'], $params),
            ];
        } else if (isset($wildcard['node']['/routes_node'])) {
            return [
                'code' => 405,
                'allowed_methods' => \array_keys($wildcard['node']['/routes_node']),
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
     * @param string $pattern
     * @return array<int, string>
     *
     * @throws InvalidArgumentException If optional parameters are not trailing.
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
                            . "in the last trailing segments of the route."
                    );
                }
                $currentSegments[] = $segment;
            }
        }

        $variants[] = \implode('/', $currentSegments);

        return $variants;
    }
}
