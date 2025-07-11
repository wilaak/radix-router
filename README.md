# RadixRouter

Simple implementation of a radix tree based router for PHP. Minimal and high-performance (see [benchmarks](#benchmark)).

### Overview

- High-performance O(k) dynamic route matching, where *k* is the number of segments in the path.
- Supports parameters, including wildcard and optional segments for flexible route definitions.
- Static routes are stored in a hash map providing near instant minimal allocation lookups for exact path matches.

## How does it work?

As the name suggests, RadixRouter utilizes a radix tree (also called a *compact prefix tree* or *Patricia trie*) to organize routes by their common prefixes. This structure enables extremely fast lookups, since each segment of the path is only compared once as the tree is traversed. Instead of checking every registered route, the router follows the path through the tree, making route matching O(k), where *k* is the number of segments in the path.

Here's a simplified visualization of how routes are stored:

```
/
├── user
│   └── :id
│       ├── profile [GET, POST] → profile_handler
│       └── update [POST] → update_handler
├── posts
│   └── :post
│       ├── [GET, POST] → post_handler
│       ├── edit [PUT] → edit_post_handler
│       └── comments
│           └── :comment [GET, DELETE] → comment_handler
├── category
│   └── :category
│       └── :item [GET] → category_item_handler
├── files
│   └── :path* [GET] → file_handler
└── search
    └── :query? [GET, POST] → search_handler
```

## Install

Install with composer:

    composer require wilaak/radix-router

Or simply include it in your project:

```PHP
require '/path/to/RadixRouter.php'
```

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

The order of route matching is: static > parameter > wildcard.

```php
// Matches only "/about"
$router->add('GET', '/about', 'handler');

// Matches both GET and POST requests to "/auth/login"
$router->add(['GET', 'POST'], '/auth/login', 'handler');

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
> When caching routes, only use handlers that can be safely represented as strings, arrays, or serializable objects.

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

This router is about as fast as you can make in pure PHP supporting dynamic segments (prove me wrong!). Routers like [FastRoute](https://github.com/nikic/FastRoute) leverage PHP's built-in regular expression engine, which is implemented in the C programming language.

### Benchmark

Here is a simple, single-threaded benchmark (Xeon E-2136, PHP 8.4.8 cli OPcache enabled):

| Metric                        | RadixRouter      | FastRoute v1      | SymfonyRouter    | Piko Router      |
|-------------------------------|-----------------:|------------------:|-----------------:|-----------------:|
| Route lookups per second      | 3,249,601        | 2,569,868         | 1,183,865        | 1,630,535        |
| Memory usage                  | 382.68 KB        | 447.32 KB         | 708.73 KB        | 432.40 KB        |
| Peak memory usage             | 468.48 KB        | 1,333.23 KB       | 1,333.36 KB      | 1,334.11 KB      |

The benchmark used 71 registered routes and tested 39 different paths. You can see the benchmark setup in the `benchmark` folder.

## License

This library is licensed under the **WTFPL-2.0**. Do whatever you want with it.