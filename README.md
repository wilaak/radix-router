# <img alt="RadixRouter" width="200" src="./assets/radx.svg">

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg?style=flat-square)

> [!NOTE]   
>  As of v3.6.0 RadixRouter is deemed to be feature complete. No major API changes are planned; only maintenance and bug fixes will be provided.

RadixRouter (or RadXRouter) is a lightweight HTTP routing library for PHP focused on providing the essentials while being fast and small. It makes an excellent choice for simple applications or as the foundation for building your own custom more featureful router (see third-party [integrations](#integrations)).

It features fast $O(k)$ dynamic route matching ($k$ = segments in path), path parameters (optional, wildcard; one per segment), simple API for listing routes/methods (for OPTIONS support), 405 method not allowed handling and it's all in a package weighing in at only ~370 lines of code with no external dependencies.

RadixRouter consistently ranks as one of the fastest PHP routers. To see how this router compares to other implementations in routing performance see the [benchmarks](#benchmarks) section.

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

// Any HTTP method (allowed or not)
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
//   /blog         -> [] (no parameters)
//   /blog/hello   -> ['slug' => 'hello']

// Chained optional parameters
$router->add('GET', '/archive/:year?/:month?', 'ArchiveController@list');
// Example requests:
//   /archive         -> [] (no parameters)
//   /archive/2022    -> ['year' => '2022']
//   /archive/2022/12 -> ['year' => '2022', 'month' => '12']

// Mixing required and optional parameters
$router->add('GET', '/shop/:category/:item?', 'ShopController@view');
// Example requests:
//   /shop/books         -> ['category' => 'books']
//   /shop/books/novel   -> ['category' => 'books', 'item' => 'novel']
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
------------------------------------------------------------
GET       /contact                  ContactController@submit
POST      /contact                  ContactController@submit
```

### Route Caching

Route caching can be beneficial for classic PHP deployments where scripts are reloaded on every request. In these environments, caching routes in a PHP file allows OPcache to keep them in memory, improving performance.

For persistent environments such as Swoole or FrankenPHP in worker mode, where the application and its routes remain in memory between requests, route caching is generally unnecessary.

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

## Important note for HEAD requests

By specification, servers must support the HEAD method for any GET resource, but without returning an entity body. In this router HEAD requests will automatically fall back to a GET route if you haven’t defined a specific HEAD route.

If you’re using PHP’s built-in web SAPI, the entity body is removed for HEAD responses automatically. If you’re implementing a custom server outside the web SAPI be sure not to send any entity body in response to HEAD requests.

## Benchmarks

In most real world situations application performance relies on many different factors, these benchmarks capture the raw routing speed for only single segment required path parameters (no wildcards) but does not test workload or congestion performance.

Most likely the router is not going to be the bottleneck of your application. You should use profilers instead of spending too much time on micro-optimizations. Do not base your entire decision on routing performance alone, nevertheless this router is very performant at these tasks.

These benchmarks are single-threaded and run on an Intel Xeon E3-1220L (20 Watt CPU from 2011), PHP 8.4.13.

- **Lookups:** Measures raw in-memory route matching speed
- **Mem:** Peak memory usage during the in-memory lookup benchmark
- **Register:** Time taken to setup the router and make the first lookup

#### Simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  RadixRouter (cached) | JIT=tracing        |     2,765,751 |      109.3 |           0.124 |
|    2 |  RadixRouter        | JIT=tracing        |     2,750,915 |      169.6 |           0.172 |
|    3 |  FastRoute (cached) | JIT=tracing        |     1,894,780 |       86.3 |           0.137 |
|    4 |  FastRoute               | JIT=tracing        |     1,618,757 |      101.9 |           0.360 |
|    5 |  RadixRouter             | OPcache            |     1,611,397 |       45.8 |           0.172 |
|    6 |  Symfony (cached)        | JIT=tracing        |     1,563,357 |      279.5 |           0.185 |
|    7 |  RadixRouter (cached)    | OPcache            |     1,560,585 |        1.4 |           0.137 |
|    8 |  FastRoute (cached)      | OPcache            |     1,403,764 |        2.4 |           0.150 |
|    9 |  Symfony                 | JIT=tracing        |     1,385,654 |      412.4 |           1.086 |
|   10 |  FastRoute               | No OPcache         |     1,358,097 |      147.2 |           8.191 |
|   11 |  RadixRouter             | No OPcache         |     1,344,039 |       45.8 |           7.132 |
|   12 |  FastRoute               | OPcache            |     1,321,569 |       16.7 |           0.404 |
|   13 |  RadixRouter (cached)    | No OPcache         |     1,310,219 |       54.7 |           7.414 |
|   14 |  FastRoute (cached)      | No OPcache         |     1,250,886 |      100.0 |           7.638 |
|   15 |  Symfony                 | OPcache            |       799,795 |       37.2 |           1.243 |
|   16 |  Symfony (cached)        | OPcache            |       790,425 |        3.2 |           0.186 |
|   17 |  Symfony (cached)        | No OPcache         |       704,386 |      237.8 |           8.822 |
|   18 |  Symfony                 | No OPcache         |       702,293 |      525.8 |          11.414 |

#### Avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  RadixRouter (cached) | JIT=tracing        |     1,760,107 |        1.9 |           0.127 |
|    2 |  RadixRouter        | JIT=tracing        |     1,647,918 |      376.1 |           0.478 |
|    3 |  RadixRouter (cached) | OPcache            |     1,084,052 |        1.9 |           0.118 |
|    4 |  RadixRouter             | OPcache            |     1,063,875 |      376.1 |           0.658 |
|    5 |  RadixRouter             | No OPcache         |       964,401 |      376.1 |           7.822 |
|    6 |  Symfony (cached)        | JIT=tracing        |       912,359 |        3.4 |           0.173 |
|    7 |  RadixRouter (cached)    | No OPcache         |       892,417 |      457.8 |           8.452 |
|    8 |  Symfony                 | JIT=tracing        |       885,368 |      283.4 |           8.028 |
|    9 |  Symfony                 | OPcache            |       574,149 |      283.4 |          12.551 |
|   10 |  Symfony (cached)        | OPcache            |       563,420 |        3.4 |           0.180 |
|   11 |  Symfony (cached)        | No OPcache         |       524,358 |      524.1 |          10.003 |
|   12 |  Symfony                 | No OPcache         |       508,470 |      772.0 |          23.562 |
|   13 |  FastRoute (cached)      | JIT=tracing        |       397,365 |        2.6 |           0.180 |
|   14 |  FastRoute               | JIT=tracing        |       363,675 |      255.8 |           3.058 |
|   15 |  FastRoute (cached)      | OPcache            |       360,030 |        2.6 |           0.171 |
|   16 |  FastRoute               | OPcache            |       349,103 |      135.7 |           4.130 |
|   17 |  FastRoute               | No OPcache         |       344,468 |      266.2 |          11.170 |
|   18 |  FastRoute (cached)      | No OPcache         |       323,993 |      240.9 |           8.467 |


#### Bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  RadixRouter (cached) | JIT=tracing        |     1,326,742 |        1.9 |           0.123 |
|    2 |  RadixRouter        | JIT=tracing        |     1,230,323 |      300.4 |           0.476 |
|    3 |  RadixRouter (cached) | OPcache            |       896,624 |        1.9 |           0.174 |
|    4 |  RadixRouter             | OPcache            |       868,440 |      300.4 |           0.618 |
|    5 |  RadixRouter             | No OPcache         |       753,647 |      300.4 |           7.859 |
|    6 |  RadixRouter (cached)    | No OPcache         |       698,294 |      365.3 |           9.020 |
|    7 |  Symfony (cached)        | JIT=tracing        |       692,757 |      120.9 |           0.189 |
|    8 |  Symfony                 | JIT=tracing        |       664,251 |      394.7 |           7.109 |
|    9 |  Symfony (cached)        | OPcache            |       431,519 |        3.5 |           0.183 |
|   10 |  Symfony                 | OPcache            |       423,181 |      211.6 |          11.272 |
|   11 |  Symfony                 | No OPcache         |       402,750 |      700.2 |          22.150 |
|   12 |  Symfony (cached)        | No OPcache         |       399,128 |      448.5 |           9.724 |
|   13 |  FastRoute (cached)      | JIT=tracing        |       212,423 |        2.7 |           0.186 |
|   14 |  FastRoute (cached)      | OPcache            |       196,772 |        2.7 |           0.158 |
|   15 |  FastRoute               | JIT=tracing        |       192,315 |      256.2 |           1.228 |
|   16 |  FastRoute               | OPcache            |       190,840 |      141.5 |           1.646 |
|   17 |  FastRoute               | No OPcache         |       186,222 |      272.0 |           9.636 |
|   18 |  FastRoute (cached)      | No OPcache         |       182,189 |      242.9 |           7.933 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  RadixRouter (cached) | JIT=tracing        |     1,291,334 |        1.5 |           0.128 |
|    2 |  RadixRouter        | JIT=tracing        |     1,232,967 |     1357.2 |           1.187 |
|    3 |  RadixRouter (cached) | OPcache            |       906,168 |        1.5 |           0.128 |
|    4 |  RadixRouter             | OPcache            |       877,240 |     1357.2 |           1.578 |
|    5 |  RadixRouter             | No OPcache         |       774,213 |     1357.2 |           9.136 |
|    6 |  RadixRouter (cached)    | No OPcache         |       698,309 |     1492.3 |          10.601 |
|    7 |  Symfony (cached)        | JIT=tracing        |       340,519 |        3.3 |           0.195 |
|    8 |  Symfony                 | JIT=tracing        |       333,978 |      579.9 |          18.309 |
|    9 |  Symfony                 | OPcache            |       265,547 |      579.9 |          27.743 |
|   10 |  Symfony (cached)        | OPcache            |       265,147 |        3.3 |           0.192 |
|   11 |  Symfony                 | No OPcache         |       251,798 |     1068.5 |          41.076 |
|   12 |  Symfony (cached)        | No OPcache         |       239,940 |      849.3 |          11.830 |
|   13 |  FastRoute (cached)      | OPcache            |       104,723 |        2.5 |           0.151 |
|   14 |  FastRoute               | OPcache            |       104,117 |      383.7 |           2.928 |
|   15 |  FastRoute               | No OPcache         |       102,844 |      514.2 |          10.522 |
|   16 |  FastRoute (cached)      | JIT=tracing        |       100,946 |       61.3 |           0.154 |
|   17 |  FastRoute (cached)      | No OPcache         |       100,777 |      508.4 |           8.842 |
|   18 |  FastRoute               | JIT=tracing        |        96,764 |      498.1 |           1.899 |

## Integrations

These are third-party integrations so evaluate and use them at your own discretion.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
