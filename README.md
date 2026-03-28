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
// Handle OPTIONS before routing
if ($method === 'OPTIONS') {
    $allowedMethods = $router->methods($path);
    header('Allow: ' . implode(', ', $allowedMethods));
    exit;
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

# Benchmarks

| | |
|---|---|
| **Date** | 2026-03-28 11:45:18 |
| **CPU** | AMD Ryzen AI 7 PRO 350 w/ Radeon 860M |
| **PHP** | 8.4.15 |
| **Suites** | avatax, bitbucket, huge, simple |
| **Routers** | FastRoute, FastRoute (cached), RadixRouter, RadixRouter (cached), Symfony, Symfony (cached) |
| **Modes** | JIT=tracing, OPcache |
| **Seed** | 42 |

## Methodology

Each suite provides a set of URL paths. For each path, 1-3 HTTP methods are assigned using a weighted
distribution (GET 60%, POST 25%, PUT 10%, DELETE 5%) to reflect typical API traffic patterns.
Dynamic segments are pre-filled with random slugs or integers, seeded for reproducibility.

Lookups are drawn from a pre-generated list that follows a Zipf-like frequency distribution (exponent 0.9),
where a small number of routes receive the majority of traffic to simulate real-world hot-path behavior instead
of a uniform distribution. The list contains at least 2000 entries or 5x the route count, shuffled using the same seed.

Each router is benchmarked inside PHP's built-in web server under multiple configurations
to capture steady-state throughput. Each combination is warmed up before measurement, and registration time
is averaged over multiple samples to reduce noise.

| Column | Description |
|--------|-------------|
| **Lookups/sec** | Steady state lookup speed in a long-running process |
| **Cold req/sec** | Estimated max requests/sec if routes are re-registered on every request (1000 / Boot ms) |
| **Mem (KB)** | Peak memory after route registration |
| **Boot (ms)** | Average time to register all routes |

#### avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Cold req/sec  | Mem (KB)   | Boot (ms)       |
|------|------------------------------|--------------------|---------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     4,152,423 |        75,657 |        0.3 |           0.013 |
|    2 | RadixRouter                  | JIT=tracing        |     3,887,560 |         3,140 |      487.5 |           0.319 |
|    3 | RadixRouter (cached)         | OPcache            |     2,899,563 |        52,983 |        0.3 |           0.019 |
|    4 | RadixRouter                  | OPcache            |     2,719,051 |         2,532 |      487.5 |           0.395 |
|    5 | Symfony                      | JIT=tracing        |     1,799,599 |           164 |      684.2 |           6.110 |
|    6 | Symfony (cached)             | JIT=tracing        |     1,785,063 |        32,544 |        2.2 |           0.031 |
|    7 | FastRoute (cached)           | JIT=tracing        |     1,434,778 |        47,884 |        1.5 |           0.021 |
|    8 | FastRoute                    | JIT=tracing        |     1,384,868 |           882 |      282.9 |           1.134 |
|    9 | Symfony (cached)             | OPcache            |     1,334,492 |        30,231 |        2.2 |           0.033 |
|   10 | Symfony                      | OPcache            |     1,291,896 |           137 |      684.2 |           7.277 |
|   11 | FastRoute (cached)           | OPcache            |     1,217,860 |        55,533 |        1.5 |           0.018 |
|   12 | FastRoute                    | OPcache            |     1,212,463 |           712 |      272.2 |           1.404 |
#### bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Cold req/sec  | Mem (KB)   | Boot (ms)       |
|------|------------------------------|--------------------|---------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     3,177,126 |        77,335 |        0.3 |           0.013 |
|    2 | RadixRouter                  | JIT=tracing        |     2,994,059 |         3,489 |      384.1 |           0.287 |
|    3 | RadixRouter (cached)         | OPcache            |     2,286,450 |        86,633 |        0.3 |           0.012 |
|    4 | RadixRouter                  | OPcache            |     2,121,526 |         3,314 |      384.1 |           0.302 |
|    5 | Symfony (cached)             | JIT=tracing        |     1,425,600 |        25,333 |        2.2 |           0.039 |
|    6 | Symfony                      | JIT=tracing        |     1,395,866 |           203 |      495.8 |           4.922 |
|    7 | Symfony (cached)             | OPcache            |     1,037,563 |        36,708 |        2.2 |           0.027 |
|    8 | Symfony                      | OPcache            |     1,026,065 |           165 |      495.8 |           6.066 |
|    9 | FastRoute (cached)           | JIT=tracing        |       554,914 |        49,880 |        1.5 |           0.020 |
|   10 | FastRoute                    | JIT=tracing        |       553,010 |         1,815 |      272.7 |           0.551 |
|   11 | FastRoute (cached)           | OPcache            |       505,036 |        53,087 |        1.5 |           0.019 |
|   12 | FastRoute                    | OPcache            |       498,229 |         1,329 |      271.7 |           0.752 |
#### huge (500 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Cold req/sec  | Mem (KB)   | Boot (ms)       |
|------|------------------------------|--------------------|---------------|---------------|------------|-----------------|
|    1 | RadixRouter (cached)         | JIT=tracing        |     3,612,440 |        76,032 |        0.3 |           0.013 |
|    2 | RadixRouter                  | JIT=tracing        |     3,294,350 |         1,562 |     1578.1 |           0.640 |
|    3 | RadixRouter (cached)         | OPcache            |     2,539,699 |        79,322 |        0.3 |           0.013 |
|    4 | RadixRouter                  | OPcache            |     2,313,815 |         1,205 |     1578.1 |           0.830 |
|    5 | Symfony (cached)             | JIT=tracing        |       894,992 |        26,690 |        2.2 |           0.037 |
|    6 | Symfony                      | JIT=tracing        |       894,139 |            75 |     1387.5 |          13.378 |
|    7 | Symfony (cached)             | OPcache            |       730,754 |        34,752 |        2.2 |           0.029 |
|    8 | Symfony                      | OPcache            |       717,685 |            63 |     1387.5 |          15.974 |
|    9 | FastRoute                    | JIT=tracing        |       589,173 |           993 |      740.1 |           1.008 |
|   10 | FastRoute (cached)           | JIT=tracing        |       581,200 |        42,823 |        1.5 |           0.023 |
|   11 | FastRoute                    | OPcache            |       517,406 |           735 |      740.1 |           1.361 |
|   12 | FastRoute (cached)           | OPcache            |       513,547 |        52,211 |        1.5 |           0.019 |
#### simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Cold req/sec  | Mem (KB)   | Boot (ms)       |
|------|------------------------------|--------------------|---------------|---------------|------------|-----------------|
|    1 | RadixRouter                  | JIT=tracing        |     9,133,154 |        23,804 |       59.0 |           0.042 |
|    2 | RadixRouter (cached)         | JIT=tracing        |     9,073,511 |        84,426 |        0.3 |           0.012 |
|    3 | FastRoute (cached)           | JIT=tracing        |     8,457,554 |        49,400 |        1.5 |           0.020 |
|    4 | FastRoute                    | JIT=tracing        |     7,889,167 |         8,395 |       31.3 |           0.119 |
|    5 | RadixRouter (cached)         | OPcache            |     5,923,071 |        84,860 |        0.3 |           0.012 |
|    6 | RadixRouter                  | OPcache            |     5,698,329 |        19,520 |       59.0 |           0.051 |
|    7 | FastRoute (cached)           | OPcache            |     5,622,346 |        52,464 |        1.5 |           0.019 |
|    8 | FastRoute                    | OPcache            |     5,484,169 |         7,435 |       30.0 |           0.135 |
|    9 | Symfony                      | JIT=tracing        |     3,551,613 |         1,539 |       87.5 |           0.650 |
|   10 | Symfony (cached)             | JIT=tracing        |     3,370,750 |        34,851 |        2.2 |           0.029 |
|   11 | Symfony (cached)             | OPcache            |     2,231,720 |        39,230 |        2.2 |           0.025 |
|   12 | Symfony                      | OPcache            |     2,183,567 |         1,691 |       87.4 |           0.591 |

## Integrations

If this router is a bit too minimalistic, you might try one of the following more high-level integrations. These are third-party so evaluate and use them at your own discretion.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
