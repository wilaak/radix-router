# <img alt="RadixRouter" width="200" src="./assets/radx.svg">

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg?style=flat-square)

> [!NOTE]   
>  As of v3.6.0 RadixRouter is seen as feature complete. No major API changes are planned; only maintenance and bug fixes will be provided.

RadixRouter (or RadXRouter) is a lightweight HTTP routing library for PHP focused on providing the essentials while being fast and small. It makes an excellent choice for simple applications or as the foundation for building your own custom more featureful router (see third-party [integrations](#integrations)).

It features fast $O(k)$ dynamic route matching ($k$ = segments in path), path parameters (optional, wildcard; one per segment), simple API for listing routes/methods (for OPTIONS support), 405 method not allowed handling and it's all in a package weighing in at only 378 lines of code with no external dependencies.

RadixRouter consistently ranks as being one of the fastest (if not *the* fastest) PHP routers out there. To see how this router compares to other implementations in routing performance see the [benchmarks](#benchmarks) section.

## Install

```bash
composer require wilaak/radix-router
```

Requires PHP 8.0 or newer.

## Usage 

Below is an example to get you started using the PHP SAPI.

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

Use `list()` to retrieve all registered routes and their associated handlers. Optionally pass a request path to filter results to routes matching that path.

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

### Listing Allowed Methods

Use `methods()` to retrieve the allowed HTTP methods for a given request path.

>[!NOTE]    
> If the fallback method (`*`) is registered for that path, `methods()` returns `$router->allowedMethods`.

```php
if ($method === 'OPTIONS') {
    $allowedMethods = $router->methods($path);
    header('Allow: ' . implode(', ', $allowedMethods));
}
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

Each suite provides a set of URL paths. For each path, 1-3 HTTP methods are assigned
using a weighted distribution (GET 60%, POST 25%, PUT 10%, DELETE 5%) to reflect typical API traffic patterns.

Lookups are drawn from a pre-generated lookup list with a Zipf-like frequency distribution (exponent 0.9),
where a small number of routes receive the majority of traffic, simulating real-world hot-path behavior.

### Setup

- **Date:** 2026-03-28 16:31:19
- **CPU:** AMD Ryzen AI 7 PRO 350 w/ Radeon 860M
- **PHP:** 8.4.15
- **Suites:** avatax, bitbucket, huge, simple
- **Routers:** FastRoute, FastRoute (cached), RadixRouter, RadixRouter (cached), Symfony, Symfony (cached)
- **Modes:** JIT=tracing, OPcache
- **Seed:** 42

### Column Reference

| Column | Description |
|:-------|:------------|
| **Lookups/sec** | Steady state lookup speed in a persistent process. |
| **Mem Peak (KB)** | Peak memory during the steady state lookup benchmark. |
| **Mem Boot (KB)** | Memory consumed after the boot process. |
| **Boot (ms)** | Time to load routes and make the first lookup, including autoload overhead. |

### Results

#### avatax (256 routes)

| Rank | Router | Mode | Lookups/sec | Peak (KB) | Reg (KB) | Boot (ms) |
|-----:|:-------|:-----|------------:|--------------:|-------------:|----------:|
| 1 | **RadixRouter (cached)** | JIT=tracing | 3,937,761 | 489.8 | 5.7 | 0.066 |
| 2 | **RadixRouter** | JIT=tracing | 3,572,481 | 951.3 | 492.7 | 0.380 |
| 3 | **RadixRouter (cached)** | OPcache | 2,762,016 | 341.4 | 5.7 | 0.067 |
| 4 | **RadixRouter** | OPcache | 2,566,884 | 828.4 | 492.7 | 0.413 |
| 5 | **Symfony (cached)** | JIT=tracing | 1,749,646 | 606.1 | 7.6 | 0.074 |
| 6 | **Symfony** | JIT=tracing | 1,682,695 | 1,553.9 | 883.4 | 6.671 |
| 7 | **FastRoute (cached)** | JIT=tracing | 1,344,367 | 460.5 | 6.9 | 0.066 |
| 8 | **FastRoute** | JIT=tracing | 1,272,916 | 1,188.6 | 675.3 | 1.326 |
| 9 | **Symfony (cached)** | OPcache | 1,253,557 | 351.1 | 7.6 | 0.068 |
| 10 | **Symfony** | OPcache | 1,229,718 | 1,106.7 | 755.4 | 7.689 |
| 11 | **FastRoute (cached)** | OPcache | 1,113,394 | 342.1 | 6.9 | 0.069 |
| 12 | **FastRoute** | OPcache | 1,099,186 | 744.5 | 409.2 | 1.487 |

#### bitbucket (177 routes)

| Rank | Router | Mode | Lookups/sec | Peak (KB) | Reg (KB) | Boot (ms) |
|-----:|:-------|:-----|------------:|--------------:|-------------:|----------:|
| 1 | **RadixRouter (cached)** | JIT=tracing | 3,108,783 | 412.2 | 4.9 | 0.064 |
| 2 | **RadixRouter** | JIT=tracing | 2,909,011 | 795.8 | 388.6 | 0.324 |
| 3 | **RadixRouter (cached)** | OPcache | 2,188,383 | 340.6 | 4.9 | 0.077 |
| 4 | **RadixRouter** | OPcache | 2,032,473 | 724.2 | 388.6 | 0.435 |
| 5 | **Symfony (cached)** | JIT=tracing | 1,361,840 | 342.6 | 6.8 | 0.108 |
| 6 | **Symfony** | JIT=tracing | 1,334,594 | 1,020.4 | 500.3 | 5.501 |
| 7 | **Symfony (cached)** | OPcache | 1,008,066 | 342.6 | 6.8 | 0.103 |
| 8 | **Symfony** | OPcache | 986,183 | 836.0 | 500.3 | 6.353 |
| 9 | **FastRoute (cached)** | JIT=tracing | 529,151 | 341.4 | 6.1 | 0.078 |
| 10 | **FastRoute** | JIT=tracing | 524,548 | 612.6 | 277.2 | 0.701 |
| 11 | **FastRoute (cached)** | OPcache | 479,498 | 341.4 | 6.1 | 0.096 |
| 12 | **FastRoute** | OPcache | 474,906 | 611.5 | 276.1 | 0.967 |

#### huge (500 routes)

| Rank | Router | Mode | Lookups/sec | Peak (KB) | Reg (KB) | Boot (ms) |
|-----:|:-------|:-----|------------:|--------------:|-------------:|----------:|
| 1 | **RadixRouter (cached)** | JIT=tracing | 3,548,760 | 340.2 | 4.9 | 0.068 |
| 2 | **RadixRouter** | JIT=tracing | 3,114,998 | 1,917.9 | 1,582.6 | 0.747 |
| 3 | **RadixRouter (cached)** | OPcache | 2,472,709 | 340.2 | 4.9 | 0.063 |
| 4 | **RadixRouter** | OPcache | 2,194,848 | 1,917.9 | 1,582.6 | 0.963 |
| 5 | **Symfony (cached)** | JIT=tracing | 846,200 | 366.3 | 6.8 | 0.087 |
| 6 | **Symfony** | JIT=tracing | 822,650 | 1,775.3 | 1,392.0 | 14.139 |
| 7 | **Symfony (cached)** | OPcache | 685,934 | 366.3 | 6.8 | 0.097 |
| 8 | **Symfony** | OPcache | 663,697 | 1,775.3 | 1,392.0 | 16.814 |
| 9 | **FastRoute** | JIT=tracing | 566,819 | 1,135.1 | 744.6 | 1.162 |
| 10 | **FastRoute (cached)** | JIT=tracing | 557,677 | 396.5 | 6.1 | 0.096 |
| 11 | **FastRoute** | OPcache | 490,259 | 1,079.9 | 744.6 | 1.760 |
| 12 | **FastRoute (cached)** | OPcache | 488,767 | 341.3 | 6.1 | 0.084 |

#### simple (33 routes)

| Rank | Router | Mode | Lookups/sec | Peak (KB) | Reg (KB) | Boot (ms) |
|-----:|:-------|:-----|------------:|--------------:|-------------:|----------:|
| 1 | **RadixRouter (cached)** | JIT=tracing | 8,797,283 | 400.2 | 4.9 | 0.058 |
| 2 | **RadixRouter** | JIT=tracing | 8,388,475 | 458.7 | 63.5 | 0.103 |
| 3 | **FastRoute (cached)** | JIT=tracing | 7,945,473 | 341.2 | 6.1 | 0.068 |
| 4 | **FastRoute** | JIT=tracing | 7,509,637 | 370.9 | 35.7 | 0.151 |
| 5 | **RadixRouter (cached)** | OPcache | 5,824,865 | 340.1 | 4.9 | 0.055 |
| 6 | **RadixRouter** | OPcache | 5,506,912 | 398.7 | 63.5 | 0.097 |
| 7 | **FastRoute (cached)** | OPcache | 5,229,159 | 341.2 | 6.1 | 0.058 |
| 8 | **FastRoute** | OPcache | 5,113,414 | 369.6 | 34.5 | 0.217 |
| 9 | **Symfony (cached)** | JIT=tracing | 3,494,126 | 504.0 | 6.8 | 0.079 |
| 10 | **Symfony** | JIT=tracing | 3,413,064 | 602.0 | 91.9 | 0.693 |
| 11 | **Symfony (cached)** | OPcache | 2,137,625 | 342.2 | 6.8 | 0.064 |
| 12 | **Symfony** | OPcache | 2,096,644 | 427.3 | 91.9 | 0.704 |

## Integrations

If this router is a bit too minimalistic, you might try one of the following more high-level integrations. These are third-party so evaluate and use them at your own discretion.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
