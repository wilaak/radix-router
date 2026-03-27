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

#### simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | radixrouter-cached           | JIT=tracing        |     8,919,978 |        0.3 |           0.014 |
|    2 | radixrouter                  | JIT=tracing        |     8,507,178 |       59.0 |           0.105 |
|    3 | fastroute-cached             | JIT=tracing        |     7,243,709 |        1.5 |           0.023 |
|    4 | fastroute                    | JIT=tracing        |     6,740,163 |       31.3 |           0.265 |
|    5 | radixrouter-cached           | OPcache            |     5,941,455 |        0.3 |           0.013 |
|    6 | radixrouter                  | OPcache            |     5,581,843 |       59.0 |           0.053 |
|    7 | fastroute-cached             | OPcache            |     5,062,444 |        1.5 |           0.021 |
|    8 | fastroute                    | OPcache            |     4,970,107 |       30.0 |           0.185 |
|    9 | symfony-cached               | JIT=tracing        |     3,326,811 |        2.2 |           0.037 |
|   10 | symfony                      | JIT=tracing        |     3,265,890 |       87.4 |           0.852 |
|   11 | symfony-cached               | OPcache            |     2,097,445 |        2.2 |           0.029 |
|   12 | symfony                      | OPcache            |     2,045,713 |       87.4 |           0.642 |


#### avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | radixrouter-cached           | JIT=tracing        |     3,787,978 |        0.3 |           0.014 |
|    2 | radixrouter                  | JIT=tracing        |     3,567,405 |      487.5 |           0.341 |
|    3 | radixrouter-cached           | OPcache            |     2,720,837 |        0.3 |           0.014 |
|    4 | radixrouter                  | OPcache            |     2,529,492 |      487.5 |           0.365 |
|    5 | symfony-cached               | JIT=tracing        |     1,629,850 |        2.2 |           0.048 |
|    6 | symfony                      | JIT=tracing        |     1,592,121 |      684.2 |           6.697 |
|    7 | fastroute-cached             | JIT=tracing        |     1,312,124 |        1.5 |           0.022 |
|    8 | fastroute                    | JIT=tracing        |     1,283,614 |      282.9 |           1.400 |
|    9 | symfony-cached               | OPcache            |     1,242,108 |        2.2 |           0.037 |
|   10 | symfony                      | OPcache            |     1,181,336 |      684.2 |           7.781 |
|   11 | fastroute-cached             | OPcache            |     1,080,295 |        1.5 |           0.020 |
|   12 | fastroute                    | OPcache            |     1,077,524 |      272.2 |           1.514 |

#### bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | radixrouter-cached           | JIT=tracing        |     3,024,664 |        0.3 |           0.014 |
|    2 | radixrouter                  | JIT=tracing        |     2,817,981 |      384.1 |           0.326 |
|    3 | radixrouter-cached           | OPcache            |     2,153,344 |        0.3 |           0.014 |
|    4 | radixrouter                  | OPcache            |     1,998,713 |      384.1 |           0.339 |
|    5 | symfony-cached               | JIT=tracing        |     1,290,028 |        2.2 |           0.046 |
|    6 | symfony                      | JIT=tracing        |     1,251,022 |      495.8 |           5.890 |
|    7 | symfony-cached               | OPcache            |       997,455 |        2.2 |           0.039 |
|    8 | symfony                      | OPcache            |       956,704 |      495.8 |           6.356 |
|    9 | fastroute-cached             | JIT=tracing        |       513,853 |        1.5 |           0.024 |
|   10 | fastroute                    | JIT=tracing        |       510,039 |      272.7 |           1.078 |
|   11 | fastroute-cached             | OPcache            |       466,358 |        1.5 |           0.026 |
|   12 | fastroute                    | OPcache            |       458,986 |      271.7 |           0.875 |

#### huge (500 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Reg (ms)        |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 | radixrouter-cached           | JIT=tracing        |     3,583,349 |        0.3 |           0.015 |
|    2 | radixrouter                  | JIT=tracing        |     3,175,191 |     1578.1 |           0.767 |
|    3 | radixrouter-cached           | OPcache            |     2,427,043 |        0.3 |           0.017 |
|    4 | radixrouter                  | OPcache            |     2,204,794 |     1578.1 |           0.897 |
|    5 | symfony-cached               | JIT=tracing        |       825,245 |        2.2 |           0.043 |
|    6 | symfony                      | JIT=tracing        |       786,530 |     1387.5 |          14.550 |
|    7 | symfony-cached               | OPcache            |       669,823 |        2.2 |           0.036 |
|    8 | symfony                      | OPcache            |       649,671 |     1387.5 |          17.197 |
|    9 | fastroute-cached             | JIT=tracing        |       562,401 |        1.5 |           0.027 |
|   10 | fastroute                    | JIT=tracing        |       560,932 |      740.1 |           1.254 |
|   11 | fastroute                    | OPcache            |       492,542 |      740.1 |           1.469 |
|   12 | fastroute-cached             | OPcache            |       488,840 |        1.5 |           0.020 |

## Integrations

If this router is a bit too minimalistic, you might try one of the following more high-level integrations. These are third-party so evaluate and use them at your own risk.

| Package | Maintainer |
|---------|-------------|
| [Mezzio Framework](https://github.com/sirix777/mezzio-radixrouter) | [sirix777](https://github.com/sirix777) |
| [Yii Framework](https://github.com/sirix777/yii-radixrouter) | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
