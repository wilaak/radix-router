# <img alt="RadixRouter" width="200" src="./assets/radx.svg">

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg?style=flat-square)

> [!NOTE]   
>  As of v3.6.0 RadixRouter is seen as feature complete. No major API changes are planned; only maintenance and bug fixes will be provided.

RadixRouter (or RadXRouter) is a lightweight HTTP routing library for PHP focused on providing the essentials while being fast and small. It makes an excellent choice for simple applications or as the foundation for building your own custom more featureful router (see third-party [integrations](#integrations)).

It features fast $O(k)$ dynamic route matching ($k$ = segments in path), path parameters (optional, wildcard; one per segment), simple API for listing routes/methods (for OPTIONS support), 405 method not allowed handling and it's all in a package weighing in at only 375 lines of code with no external dependencies.

RadixRouter consistently ranks as being one of the fastest (if not *the* fastest) PHP routers out there. To see how this router compares to other implementations in routing performance see the [benchmarks](#benchmarks) section.

## Install

```bash
composer require wilaak/radix-router
```

Requires PHP 8.0 or newer.

## Usage 

Below is an example to get you started.

```PHP
$router = new Wilaak\Http\RadixRouter();

$router->add('GET', '/:name?', function ($name = 'World') {
    echo "Hello, {$name}!";
});

$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(strtok($_SERVER['REQUEST_URI'], '?'));

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
        $allowedMethods = $result['allowed_methods'];
        header('Allow: ' . implode(',', $allowedMethods));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

## Route Configuration

Routes are matched in a predictable order, always favoring the most specific pattern. Handlers can be any value you choose. In these examples, we use strings for simplicity, but you’re free to use arrays with extra details like middleware or other metadata. Just remember: if you plan to use [route caching](#route-caching), your handlers must be serializable.

The router does not support regex patterns and it's recommended that you do this validation in your handlers. Regular expressions allow for highly flexible matching which can make route resolution order less predictable. If you absolutely need this you can use a more suitable router of which there are plenty (e.g., [FastRoute](https://github.com/nikic/FastRoute)).

```php
// Simple GET route
$router->add('GET', '/about', 'AboutController@show');

// Multiple HTTP methods
$router->add(['GET', 'POST'], '/contact', 'ContactController@submit');

// Any allowed HTTP method
$router->add($router->allowedMethods, '/maintenance', 'MaintenanceController@handle');

// Special fallback HTTP method (allowed or not)
$router->add('*', '/maintenance', 'MaintenanceController@handle');
```

### Path Parameters

Path parameters let you capture segments of the request path by specifying named placeholders in your route pattern. The router extracts these values and returns them as a map, with each value bound to its corresponding parameter name.

#### Required Parameters

Matches only when the segment is present and not empty.

```php
// Required parameter
$router->add('GET', '/users/:id', 'UserController@profile');
// Example requests:
//   /users     -> no match
//   /users/123 -> ['id' => '123']

// You can have as many as you want, but keep it sane
$router->add('GET', '/users/:id/orders/:order_id', 'OrderController@details');
```

#### Optional Parameters

These match whether the segment is present or not. 

> [!TIP]   
> Use sparingly! In most cases you’re probably better off using query parameters instead of restricting yourself to a single filtering option in the path.


```php
// Single optional parameter
$router->add('GET', '/blog/:slug?', 'BlogController@view');
// Example requests:
//   /blog       -> [] (no parameters)
//   /blog/hello -> ['slug' => 'hello']

// Chained optional parameters
$router->add('GET', '/archive/:year?/:month?', 'ArchiveController@list');
// Example requests:
//   /archive         -> [] (no parameters)
//   /archive/2022    -> ['year' => '2022']
//   /archive/2022/12 -> ['year' => '2022', 'month' => '12']

// Mixing required and optional parameters
$router->add('GET', '/shop/:category/:item?', 'ShopController@view');
// Example requests:
//   /shop/books       -> ['category' => 'books']
//   /shop/books/novel -> ['category' => 'books', 'item' => 'novel']
```

#### Wildcard Parameters

Also known as catch-all, splat, greedy, rest, or path remainder parameters, wildcards capture everything after their position in the path including slashes. Note that the router trims trailing slashes from incoming paths, so you won’t see empty segments at the end of your results.

> [!CAUTION]    
> Never use captured path segments directly in filesystem operations. Path traversal attacks can expose sensitive files or directories. Use functions like `realpath()` and restrict access to a safe base directory.

```php
// Required wildcard parameter (one or more segments)
$router->add('GET', '/assets/:resource+', 'AssetController@show');
// Example requests:
//   /assets                -> no match
//   /assets/logo.png       -> ['resource' => 'logo.png']
//   /assets/img/banner.jpg -> ['resource' => 'img/banner.jpg']

// Optional wildcard parameter (zero or more segments)
$router->add('GET', '/downloads/:file*', 'DownloadController@show');
// Example requests:
//   /downloads               -> ['file' => ''] (empty string)
//   /downloads/report.pdf    -> ['file' => 'report.pdf']
//   /downloads/docs/guide.md -> ['file' => 'docs/guide.md']
```

### Route Listing

The router provides a convenient method for listing routes and their associated handlers.

```php
// Print a formatted table of all routes
function print_routes_table($routes) {
    printf("%-8s  %-24s  %s\n", 'METHOD', 'PATTERN', 'HANDLER');
    printf("%s\n", str_repeat('-', 60));
    foreach ($routes as $route) {
        printf("%-8s  %-24s  %s\n", $route['method'], $route['pattern'], $route['handler']);
    }
    printf("%s\n", str_repeat('-', 60));
}

// List all routes
print_routes_table($router->list());

// List routes for a specific path
print_routes_table($router->list('/contact'));
```

Example Output:

```
METHOD    PATTERN                   HANDLER
------------------------------------------------------------
GET       /about                    AboutController@show
GET       /archive/:year?/:month?   ArchiveController@list
GET       /assets/:resource+        AssetController@show
GET       /blog/:slug?              BlogController@view
GET       /contact                  ContactController@submit
POST      /contact                  ContactController@submit
GET       /downloads/:file*         DownloadController@show
*         /maintenance              MaintenanceController@handle
GET       /users/:id                UserController@profile

METHOD    PATTERN                   HANDLER
------------------------------------------------------------
GET       /contact                  ContactController@submit
POST      /contact                  ContactController@submit
```

### Route Caching

Route caching can be beneficial for classic PHP deployments where scripts are reloaded on every request. In these environments, caching routes in a PHP file allows OPcache to keep them in shared memory, significantly reducing script startup time by eliminating the need to recompile route definitions on each request.

An additional benefit of storing routes in PHP files is that PHP’s engine automatically interns identical string literals at compile time. This means that when multiple routes share the same pattern, method or handler name, only a single instance of each unique string is stored in memory, reducing memory usage and access latency.

For persistent environments such as Swoole or FrankenPHP in worker mode, where the application and its routes remain in memory between requests, route caching is generally unnecessary. However you may still gain some performance uplift from the aforementioned interning, which you may also notice in the [benchmark](#benchmarks) results.

> [!NOTE]  
> You must only provide serializable handlers such as strings or arrays. Closures and anonymous functions are not supported for route caching.

> [!WARNING]  
> Care should be taken to avoid race conditions when rebuilding the cache file. Ensure that the cache is written atomically so that each request can always fully load a valid cache file without errors or partial data.

```php
$cache = __DIR__ . '/radixrouter.cache.php';

if (!file_exists($cache)) {
    $router->add('GET', '/', 'handler');
    // ...add more routes

    $routes = [
        'tree' => $router->tree,
        'static' => $router->static,
    ];

    file_put_contents($cache, '<?php return ' . var_export($routes, true) . ';');
}

$routes = require $cache;
$router->tree = $routes['tree'];
$router->static = $routes['static'];
```

### Custom HTTP methods

You can easily add custom HTTP methods to the router, just make sure the method names are uppercase as validation only happens when you add routes.

```php
$customMethods = ['PURGE', 'REPORT'];
$router->allowedMethods = array_merge($router->allowedMethods, $customMethods);
```

If you want a route to match any HTTP method (including custom ones), use the fallback method:

```php
$router->add('*', '/somewhere', 'handler');
```

### Canonical URLs

The router does not perform any automatic trailing slash redirects. Trailing slashes at the end of the request path are automatically trimmed before route matching, so both `/about` and `/about/` will match the same route.

When a route is successfully matched, the lookup method returns the canonical pattern of the matched route, allowing you to implement your own logic to enforce a specific URL format or redirect as needed.

## Important note on HEAD requests

By specification, servers must support the HEAD method for any GET resource, but without returning an entity body. In this router HEAD requests will automatically fall back to a GET route if you haven’t defined a specific HEAD route.

If you’re using PHP’s built-in web SAPI, the entity body is removed for HEAD responses automatically. If you’re implementing a custom server outside the web SAPI be sure not to send any entity body in response to HEAD requests.

## Benchmarks

Most likely the router will not be your application's bottleneck. Instead of focusing on micro-optimizations, you should use profilers to identify performance issues (unless you're into that kinda thing).

These benchmarks are single-threaded and run on an AMD Ryzen AI PRO 350, PHP 8.4.15.

- **Lookups/sec:** Measures steady state route matching speed.
- **Mem (KB):** Peak memory usage during the steady state matching benchmark.
- **Reg (ms):** Time taken to setup the router and make the first lookup.

#### avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     3,794,804 |        0.3 |           0.014 |
|    2 | RadixRouter                  | JIT=tracing        |     3,488,322 |      487.5 |           0.333 |
|    3 | RadixRouter (cached)         | OPcache            |     2,704,738 |        0.3 |           0.013 |
|    4 | RadixRouter                  | OPcache            |     2,558,398 |      487.5 |           0.373 |
|    5 | Symfony (cached)             | JIT=tracing        |     1,636,046 |        2.2 |           0.037 |
|    6 | Symfony                      | JIT=tracing        |     1,620,686 |      684.2 |           6.530 |
|    7 | FastRoute (cached)           | JIT=tracing        |     1,301,415 |        1.5 |           0.022 |
|    8 | FastRoute                    | JIT=tracing        |     1,267,478 |      282.9 |           1.252 |
|    9 | Symfony (cached)             | OPcache            |     1,240,379 |        2.2 |           0.029 |
|   10 | Symfony                      | OPcache            |     1,198,298 |      684.2 |           7.766 |
|   11 | FastRoute (cached)           | OPcache            |     1,075,436 |        1.5 |           0.020 |
|   12 | FastRoute                    | OPcache            |     1,059,843 |      272.2 |           1.522 |
#### bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     2,994,402 |        0.3 |           0.017 |
|    2 | RadixRouter                  | JIT=tracing        |     2,769,092 |      384.1 |           0.283 |
|    3 | RadixRouter (cached)         | OPcache            |     2,155,614 |        0.3 |           0.013 |
|    4 | RadixRouter                  | OPcache            |     2,032,977 |      384.1 |           0.329 |
|    5 | Symfony (cached)             | JIT=tracing        |     1,289,280 |        2.2 |           0.036 |
|    6 | Symfony                      | JIT=tracing        |     1,265,327 |      495.8 |           5.353 |
|    7 | Symfony (cached)             | OPcache            |       986,946 |        2.2 |           0.031 |
|    8 | Symfony                      | OPcache            |       960,464 |      495.8 |           6.551 |
|    9 | FastRoute (cached)           | JIT=tracing        |       513,816 |        1.5 |           0.022 |
|   10 | FastRoute                    | JIT=tracing        |       510,876 |      272.7 |           0.602 |
|   11 | FastRoute (cached)           | OPcache            |       467,498 |        1.5 |           0.027 |
|   12 | FastRoute                    | OPcache            |       460,381 |      271.7 |           0.797 |
#### huge (500 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     3,383,164 |        0.3 |           0.015 |
|    2 | RadixRouter                  | JIT=tracing        |     3,034,489 |     1578.1 |           0.706 |
|    3 | RadixRouter (cached)         | OPcache            |     2,427,080 |        0.3 |           0.013 |
|    4 | RadixRouter                  | OPcache            |     2,230,887 |     1578.1 |           0.890 |
|    5 | Symfony (cached)             | JIT=tracing        |       813,751 |        2.2 |           0.037 |
|    6 | Symfony                      | JIT=tracing        |       795,392 |     1387.5 |          13.982 |
|    7 | Symfony (cached)             | OPcache            |       670,316 |        2.2 |           0.047 |
|    8 | Symfony                      | OPcache            |       649,458 |     1387.5 |          17.311 |
|    9 | FastRoute                    | JIT=tracing        |       549,125 |      740.1 |           1.115 |
|   10 | FastRoute (cached)           | JIT=tracing        |       546,671 |        1.5 |           0.023 |
|   11 | FastRoute                    | OPcache            |       477,158 |      740.1 |           1.422 |
|   12 | FastRoute (cached)           | OPcache            |       475,693 |        1.5 |           0.021 |
#### simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     8,656,299 |        0.3 |           0.013 |
|    2 | RadixRouter                  | JIT=tracing        |     8,023,242 |       59.0 |           0.042 |
|    3 | FastRoute (cached)           | JIT=tracing        |     7,728,428 |        1.5 |           0.023 |
|    4 | FastRoute                    | JIT=tracing        |     7,205,504 |       31.3 |           0.114 |
|    5 | RadixRouter (cached)         | OPcache            |     5,943,960 |        0.3 |           0.013 |
|    6 | RadixRouter                  | OPcache            |     5,691,536 |       59.0 |           0.056 |
|    7 | FastRoute (cached)           | OPcache            |     5,199,690 |        1.5 |           0.026 |
|    8 | FastRoute                    | OPcache            |     5,071,771 |       30.0 |           0.136 |
|    9 | Symfony                      | JIT=tracing        |     3,192,750 |       87.5 |           0.689 |
|   10 | Symfony (cached)             | JIT=tracing        |     3,165,688 |        2.2 |           0.035 |
|   11 | Symfony (cached)             | OPcache            |     2,089,209 |        2.2 |           0.029 |
|   12 | Symfony                      | OPcache            |     2,052,239 |       87.4 |           0.635 |


## Integrations

If this router is a bit too minimalistic, you might try one of the following more high-level integrations. These are third-party so evaluate and use them at your own risk.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
