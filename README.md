# RadixRouter

Simple implementation of a radix tree based router for PHP. Minimal and high-performance (see [benchmarks](#benchmarks)).

### Overview

- High-performance O(k) dynamic route matching, where *k* is the number of segments in the path.
- Supports parameters, including wildcard and optional segments for flexible route definitions.
- Static routes are stored in a hash map providing near instant minimal allocation lookups for exact path matches.
- Trailing slashes are ignored, so `/foo` and `/foo/` are treated as the same route.

## How does it work?

As the name suggests, RadixRouter utilizes a radix tree (also called a *compact prefix tree* or *Patricia trie*) to organize routes by their common prefixes. This structure enables extremely fast lookups, since each segment of the path is only compared once as the tree is traversed. Instead of checking every registered route, the router follows the path through the tree:

![Radix Tree Diagram](assets/tree.svg)

## Install

Install with composer:

    composer require wilaak/radix-router

Or simply include it in your project:

```PHP
require '/path/to/RadixRouter.php'
```

Requires PHP 8.0 or newer. (PHP 8.3 for tests)

## Usage

Here's a basic usage example:

```php
use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->add('GET', '/:world?', function ($world = 'World') {
    echo "Hello, $world!";
});

$method = strtoupper(
    $_SERVER['REQUEST_METHOD']
);
$path = rawurldecode(
    strtok($_SERVER['REQUEST_URI'], '?')
);

$result = $router->lookup($method, $path);

switch ($result['code']) {
    case 200:
        $result['handler'](...$result['params']);
        break;

    case 404:
        http_response_code(404);
        echo '404 Not Found';
        break;

    case 405:
        header('Allow: ' . implode(', ', $result['allowed_methods']));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

## Registering routes

Routes are registered using the `add()` method. You can assign any value as the handler.

The order of route matching is: static > parameter.

```php
// Register a route for both GET and POST methods
$router->add(['GET', 'POST'], '/hello', 'handler');

// Register a static route for a single method
$router->add('GET', '/about', 'aboutHandler');
```

**Required parameters:**

These parameters must be present or the route will not be matched:

```php
$router->add('GET', '/users/:id', 'handler');

// /users        -> no match
// /users/123    -> match (captures "123")
// /users/alice  -> match (captures "alice")
```


**Optional parameters:**

These parameters let you capture segments that may not always be present in the path:

```php
$router->add('GET', '/hello/:name?', 'handler');

// /hello        -> match (no parameters)
// /hello/alice  -> match (captures "alice")
```

You can chain multiple trailing optional parameters:

```php
$router->add('GET', '/archive/:year?/:month?', 'handler');

// /archive          -> match (no parameters)
// /archive/2024     -> match (captures "2024")
// /archive/2024/06  -> match (captures "2024", "06")
```

**Wildcard parameters:**

These are only allowed as the last segment of the route.

> **Note:** Overlapping dynamic routes will not fall back to wildcards; if you define a route like `/files/foo/:bar` and a wildcard like `/files/:path*`, requests to `/files/foo/bar/baz` will result in a 404 status code.

```php
$router->add('GET', '/files/:path*', 'handler');

// /files                 -> match: wildcard (captures "")
// /files/download        -> match: wildcard (captures "download")
// /files/anything/else/  -> match: wildcard (captures "anything/else")
```

## How to Cache Routes

Rebuilding the route tree on every request or application startup can slow down performance.

> **Note:**
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized. When caching routes, only use handlers that can be safely represented as strings, arrays, or serializable objects.

> **Note:**
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```php
$cacheFile = __DIR__ . '/routes.cache.php';
if (!file_exists($cacheFile)) {
    // Build routes here
    $router->add('GET', '/', 'handler');
    // Export generated tree and static routes
    $routes = [
        'tree' => $router->tree,
        'static' => $router->static,
    ];
    file_put_contents($cacheFile, '<?php return ' . var_export($routes, true) . ';');
} else {
    // Load tree and static routes from cache
    $routes = require $cacheFile;
    $router->tree = $routes['tree'];
    $router->static = $routes['static'];
}
```

By storing your routes in a PHP file, you let PHP’s OPcache handle the heavy lifting, making startup times nearly instantaneous.

## Note on HEAD Requests

According to the HTTP specification, any route that handles a GET request should also support HEAD requests. RadixRouter does not automatically add this behavior. If you are running outside a standard web server environment (such as in a custom server), ensure that your GET routes also respond appropriately to HEAD requests. Responses to HEAD requests must not include a message body.

## Performance

This router is about as fast as you can make in pure PHP supporting dynamic segments (prove me wrong!).

### Benchmarks

Single-threaded benchmark (Xeon E-2136, PHP 8.4.8 cli OPcache enabled):

#### Simple app (33 routes)

| Router           | Register      | Lookups       | Memory      | Peak Mem      |
|------------------|--------------|--------------|-------------|--------------|
| **RadixRouter**  | 0.04 ms      | 3,233,227/sec | 375 KB      | 456 KB       |
| **FastRoute**    | 1.85 ms      | 2,767,883/sec | 431 KB      | 1,328 KB     |
| **SymfonyRouter**| 6.24 ms      | 1,722,432/sec | 574 KB      | 1,328 KB     |

#### Avatax API (256 routes)

| Router           | Register     | Lookups       | Memory      | Peak Mem      |
|------------------|--------------|-------------- |-------------|--------------|
| **RadixRouter**  | 0.25 ms      | 2,127,808/sec | 587 KB      | 588 KB       |
| **FastRoute**    | 4.94 ms      |   707,516/sec | 549 KB      | 1,328 KB     |
| **SymfonyRouter**| 12.60 ms     | 1,182,060/sec | 1,292 KB    | 1,588 KB     |

#### Bitbucket API (178 routes)

| Router           | Register      | Lookups       | Memory      | Peak Mem      |
|------------------|--------------|--------------|-------------|--------------|
| **RadixRouter**  | 0.17 ms      | 1,781,226/sec | 532 KB      | 533 KB       |
| **FastRoute**    | 3.81 ms      |   371,104/sec | 556 KB      | 1,328 KB     |
| **SymfonyRouter**| 12.16 ms     |   910,064/sec | 1,186 KB    | 1,426 KB     |

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.