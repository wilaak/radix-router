# RadixRouter

Simple implementation of a radix tree based router for PHP.

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

Requires PHP 8.1 or newer.

## Usage

Here's a basic usage example:

```php
use Wilaak\Http\RadixRouter;

$router = new RadixRouter();

$router->add(['GET'], '/', function () {
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

### Defining routes

Define routes using the `add()` method.

```php
// Static route: matches only "/about"
$router->add(['GET'], '/about', 'handler');

// Parameterized route: matches "/user/123" but NOT "/user/"
$router->add(['GET'], '/user/:id', 'handler');

// Optional parameter: matches "/posts/abc" and "/posts/"
// Note: Only allowed as the last segment.
$router->add(['GET'], '/posts/:id?', 'handler');

// Wildcard parameter: matches "/files/static/dog.jpg" and "/files/"
// Note: Only allowed as the last segment and can be shadowed by more specific routes.
// For example, if you register both "/files/:path*" and "/files/foo", "/files/foo/bar.txt" will not match the wildcard.
$router->add(['GET'], '/files/:path*', 'handler');

// Multiple parameters: matches "/posts/42/comments/7"
$router->add(['GET'], '/posts/:post/comments/:comment', 'handler');

// Multiple methods: matches both GET and POST requests to "/login"
$router->add(['GET', 'POST'], '/login', 'handler');
```

Routes are matched in order: static routes first, parameterized next and wildcards last.

### How to Cache Routes

Rebuilding the route tree on every request or application startup can slow down performance.

> **Note:**  
> Anonymous functions (closures) are **not supported** for route caching because they cannot be serialized.

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

### Note on HEAD Requests

According to the HTTP specification, any route that handles a GET request should also support HEAD requests. RadixRouter does not automatically add this behavior. If you are running outside a standard web server environment (such as in a custom server), ensure that your GET routes also respond appropriately to HEAD requests. Responses to HEAD requests must not include a message body.

## Performance

You can expect perfomance similar to [FastRoute](https://github.com/nikic/FastRoute), in some cases its faster e.g large segments, in some cases its slower e.g wilcards and deep static routes. However FastRoute is much more featured, supporting regex matching, inline parameters, wildcard fallbacks and more. If there was a router that I would choose it would probably be FastRoute.

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