# <img alt="RadixRouter" width="200" src="./assets/radx.svg">

> [!NOTE]   
>  As of v3.6.0 RadixRouter is seen as feature complete. No major API changes are planned; only maintenance and bug fixes will be provided.

RadixRouter (or RadXRouter) is a lightweight HTTP routing library for PHP focused on providing the essentials while being fast and small. It makes an excellent choice for simple applications or as the foundation for building your own custom more featureful router (see third-party [integrations](#integrations)).

It features fast $O(k)$ dynamic route matching ($k$ = segments in path), path parameters (optional, wildcard; one per segment), simple API for listing routes/methods (for OPTIONS support), 405 method not allowed handling and it's all in a package weighing in at only 352 lines of code with no external dependencies.

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

- **Lookups:** Measures raw in-memory route matching speed
- **Mem:** Peak memory usage during the in-memory lookup benchmark
- **Register:** Time taken to setup the router and make the first lookup

#### Simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     7,674,396 |      173.3 |           0.048 |
|    2 |  **RadixRouter**        | JIT=tracing        |     6,992,979 |      233.6 |           0.069 |
|    3 |  **FastRoute (cached)** | JIT=tracing        |     5,762,339 |       86.8 |           0.060 |
|    4 |  **FastRoute**               | JIT=tracing        |     5,596,129 |      102.4 |           0.137 |
|    5 |  **RadixRouter (cached)**    | No OPcache         |     5,162,456 |       54.7 |           2.596 |
|    6 |  **RadixRouter (cached)**    | OPcache            |     4,484,906 |        1.4 |           0.050 |
|    7 |  **FastRoute (cached)**      | OPcache            |     4,253,858 |        2.4 |           0.058 |
|    8 |  **FastRoute**               | OPcache            |     4,159,336 |       16.7 |           0.135 |
|    9 |  **Symfony (cached)**        | JIT=tracing        |     4,136,627 |      284.5 |           0.071 |
|   10 |  **RadixRouter**             | OPcache            |     4,099,163 |       45.8 |           0.072 |
|   11 |  **Symfony**                 | JIT=tracing        |     3,872,158 |      413.4 |           0.388 |
|   12 |  **FastRoute**               | No OPcache         |     3,862,327 |      147.2 |           2.994 |
|   13 |  **RadixRouter**             | No OPcache         |     3,748,498 |       45.8 |           2.553 |
|   14 |  **FastRoute (cached)**      | No OPcache         |     3,696,746 |      100.0 |           2.593 |
|   15 |  **Symfony**                 | OPcache            |     3,561,464 |       37.2 |           0.375 |
|   16 |  **Symfony (cached)**        | OPcache            |     2,373,401 |        3.2 |           0.069 |
|   17 |  **Symfony**                 | No OPcache         |     2,153,155 |      527.4 |           4.398 |
|   18 |  **Symfony (cached)**        | No OPcache         |     2,113,642 |      238.2 |           3.109 |

#### Avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     4,281,604 |        1.9 |           0.055 |
|    2 |  **RadixRouter**        | JIT=tracing        |     4,235,524 |      376.1 |           0.173 |
|    3 |  **RadixRouter**        | No OPcache         |     3,564,568 |      376.1 |           2.794 |
|    4 |  **RadixRouter (cached)**    | OPcache            |     2,993,789 |        1.9 |           0.052 |
|    5 |  **RadixRouter**             | OPcache            |     2,723,967 |      376.1 |           0.226 |
|    6 |  **Symfony (cached)**        | JIT=tracing        |     2,443,603 |        3.4 |           0.066 |
|    7 |  **RadixRouter (cached)**    | No OPcache         |     2,369,209 |      457.8 |           2.982 |
|    8 |  **Symfony**                 | JIT=tracing        |     2,347,725 |      283.4 |           2.812 |
|    9 |  **Symfony (cached)**        | No OPcache         |     2,166,980 |      524.5 |           3.598 |
|   10 |  **Symfony (cached)**        | OPcache            |     1,669,518 |        3.4 |           0.065 |
|   11 |  **Symfony**                 | OPcache            |     1,633,724 |      283.4 |           3.824 |
|   12 |  **FastRoute (cached)**      | OPcache            |     1,584,171 |        2.6 |           0.056 |
|   13 |  **Symfony**                 | No OPcache         |     1,486,302 |      773.6 |           8.569 |
|   14 |  **FastRoute (cached)**      | JIT=tracing        |     1,201,718 |        2.6 |           0.058 |
|   15 |  **FastRoute**               | JIT=tracing        |     1,182,944 |      255.8 |           0.808 |
|   16 |  **FastRoute**               | OPcache            |     1,025,419 |      135.7 |           1.042 |
|   17 |  **FastRoute**               | No OPcache         |     1,012,465 |      266.2 |           3.879 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       985,669 |      240.9 |           2.807 |

#### Bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     3,685,780 |        1.9 |           0.055 |
|    2 |  **RadixRouter**        | JIT=tracing        |     3,390,184 |      300.4 |           0.183 |
|    3 |  **Symfony (cached)**   | JIT=tracing        |     2,664,959 |      120.9 |           0.071 |
|    4 |  **RadixRouter (cached)**    | OPcache            |     2,357,956 |        1.9 |           0.051 |
|    5 |  **RadixRouter**             | OPcache            |     2,209,225 |      300.4 |           0.213 |
|    6 |  **RadixRouter**             | No OPcache         |     1,976,791 |      300.4 |           2.851 |
|    7 |  **RadixRouter (cached)**    | No OPcache         |     1,887,156 |      365.3 |           3.015 |
|    8 |  **Symfony**                 | JIT=tracing        |     1,708,013 |      395.2 |           2.464 |
|    9 |  **Symfony (cached)**        | OPcache            |     1,275,675 |        3.5 |           0.069 |
|   10 |  **Symfony**                 | OPcache            |     1,246,299 |      211.6 |           3.269 |
|   11 |  **Symfony (cached)**        | No OPcache         |     1,169,201 |      448.9 |           3.510 |
|   12 |  **Symfony**                 | No OPcache         |     1,156,611 |      701.8 |           7.955 |
|   13 |  **FastRoute**               | OPcache            |       863,800 |      141.5 |           0.497 |
|   14 |  **FastRoute**               | JIT=tracing        |       637,042 |      256.2 |           0.411 |
|   15 |  **FastRoute (cached)**      | JIT=tracing        |       631,046 |        2.7 |           0.067 |
|   16 |  **FastRoute (cached)**      | OPcache            |       574,979 |        2.7 |           0.064 |
|   17 |  **FastRoute (cached)**      | No OPcache         |       549,127 |      242.9 |           2.797 |
|   18 |  **FastRoute**               | No OPcache         |       539,484 |      272.0 |           3.279 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     3,711,381 |        1.5 |           0.054 |
|    2 |  **RadixRouter (cached)** | OPcache            |     3,544,975 |        1.5 |           0.057 |
|    3 |  **RadixRouter**        | JIT=tracing        |     3,031,935 |     1357.2 |           0.393 |
|    4 |  **RadixRouter**             | No OPcache         |     1,891,608 |     1357.2 |           3.488 |
|    5 |  **RadixRouter (cached)**    | No OPcache         |     1,799,815 |     1464.5 |           4.228 |
|    6 |  **RadixRouter**             | OPcache            |     1,765,558 |     1357.2 |           0.498 |
|    7 |  **Symfony**                 | JIT=tracing        |     1,425,057 |      579.9 |           6.796 |
|    8 |  **Symfony (cached)**        | JIT=tracing        |       962,096 |      187.3 |           0.074 |
|    9 |  **Symfony (cached)**        | OPcache            |       759,199 |        3.3 |           0.074 |
|   10 |  **Symfony**                 | OPcache            |       749,538 |      579.9 |           8.624 |
|   11 |  **Symfony**                 | No OPcache         |       724,370 |     1070.1 |          13.805 |
|   12 |  **Symfony (cached)**        | No OPcache         |       701,889 |     1048.9 |           4.221 |
|   13 |  **FastRoute**               | JIT=tracing        |       417,430 |      498.1 |           0.789 |
|   14 |  **FastRoute (cached)**      | JIT=tracing        |       414,888 |       61.3 |           0.069 |
|   15 |  **FastRoute**               | OPcache            |       359,533 |      383.7 |           0.925 |
|   16 |  **FastRoute**               | No OPcache         |       353,453 |      514.2 |           3.633 |
|   17 |  **FastRoute (cached)**      | OPcache            |       353,027 |        2.5 |           0.064 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       342,032 |      508.4 |           3.048 |

## Integrations

If this router is a bit too minimalistic, you might try one of the following more high-level integrations. These are third-party so evaluate and use them at your own risk.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
