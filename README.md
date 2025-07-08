# RadixRouter

Simple implementation of a radix tree based router for PHP

## How does it work?

A radix tree data structure (also known as a *compact prefix tree* or *Patricia trie*) allows for highly efficient lookups by minimizing redundant comparisons and grouping common prefixes together. As a result, route matching is performed in O(k) time, where k is the length of the path, rather than the number of registered routes:

```
(root)
 ├── /
 ├── about
 ├── user
 │    └── :x
 │         └── profile
 └── posts
    └── :x
         ├── comments
         │    └── :x
         └── edit
```

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.3 or newer.

## Usage example

Here's a basic usage example:

```php
use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->add('GET', '/', function () {
    echo "Hello, World!";
});

$method = strtoupper(
    $_SERVER['REQUEST_METHOD']
);
$path = rawurldecode(
    strtok($_SERVER['REQUEST_URI'], '?')
);

$info = $router->lookup($method, $path);

switch ($info['code']) {
    case 200:
        $info['handler'](...$info['params']);
        break;

    case 404:
        http_response_code(404);
        echo '404 Not Found';
        break;

    case 405:
        header('Allow: ' . implode(', ', $info['allowed_methods']));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

## Defining Routes

Routes are defined using the `add()` method. You can assign any value as the handler.

The order or route matching is: static > parameter > wildcard.

```php
// Matches only "/about"
$router->add('GET', '/about', 'handler');

// Matches both GET and POST requests to "/auth/login"
$router->add(['GET', 'POST'], '/auth/login', 'handler');
```

**Required Parameters:**

Parameters are defined usding the colon prefix:

```php
// Matches "/user/123" (captures "123"), but NOT "/user/"
$router->add('GET', '/user/:id', 'handler');

// Matches "/posts/42/comments/7" (captures "42" and "7")
$router->add('GET', '/posts/:post/comments/:comment', 'handler');
```

**Optional Parameters:**

These are only allowed as the last segment of the route. 

```php
// Matches "/posts/abc" (captures "abc") and "/posts/" (provides no parameter)
$router->add('GET', '/posts/:id?', 'handler');
```

**Wildcard Parameters:**

These are only allowed as the last segment of the route. 

> **Note:**
> Overlapping patterns will not fall back to wildcards. If you register a route like `/files/foo` and a wildcard route like `/files/:path*`, requests to `/files/foo/bar.txt` will result in a 404 Not Found error.

```php
// Matches "/files/static/dog.jpg" (captures "static/dog.jpg") and "/files/" (captures empty string)
$router->add('GET', '/files/:path*', 'handler');
```

## How to Cache Routes

Rebuilding the route tree on every request or application startup can slow down performance.

> **Note:**
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.
> 
> Other values that cannot be cached in PHP include:
> - Resources (such as file handles, database connections)
> - Objects that are not serializable
> - References to external state (like open sockets)
> 
> When caching routes, only use handlers and parameters that can be safely represented as strings, arrays, or serializable objects.

> **Note:**
> When implementing route caching, care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

Here is a simple cache implementation:

```php
$cacheFile = __DIR__ . '/routes.cache.php';
if (!file_exists($cacheFile)) {
    // Build routes here
    $router->add(['GET'], '/', 'handler');
    // Export generated routes 
    file_put_contents($cacheFile, '<?php return ' . var_export($router->routes, true) . ';');
} else {
    // Load routes from cache
    $router->routes = require $cacheFile;
}
```

By storing your routes in a PHP file, you let PHP’s OPcache handle the heavy lifting, making startup times nearly instantaneous.

## Note on HEAD Requests

According to the HTTP specification, any route that handles a GET request should also support HEAD requests. RadixRouter does not automatically add this behavior. If you are running outside a standard web server environment (such as in a custom server), ensure that your GET routes also respond appropriately to HEAD requests. Responses to HEAD requests must not include a message body.

## Performance

You can expect performance similar to [FastRoute](https://github.com/nikic/FastRoute). However FastRoute is much more featured, supporting regex matching, inline parameters, wildcard fallbacks and more. If there was a router that I would choose it would probably be FastRoute.

This router is about as fast as you can make in pure PHP supporting dynamic segments (prove me wrong!). Routers like FastRoute leverage PHP's built-in regular expression engine, which is implemented in the C programming language.

### Benchmark

Here is a simple, single-threaded benchmark (Xeon E-2136, PHP 8.4.8 cli):

| Metric                        | FastRoute v1      | RadixRouter      |
|-------------------------------|------------------:|-----------------:|
| Route lookups per second      | 2,420,439.87      | 2,441,495.67     |
| Memory usage                  | 643.23 KB         | 489.06 KB        |
| Peak memory usage             | 680.77 KB         | 507.34 KB        |

The benchmark used 71 registered routes and tested 39 different paths. The benchmark consists mostly of dynamic routes, which favors RadixRouter. You can see the benchmark setup in the `benchmarks` folder.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.