![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg?style=flat-square)

<p align="center"><img width="200" src="./assets/graph.svg"></p>

# RadixRouter

This library provides a minimal HTTP request router implementation (see [benchmarks](#benchmarks) and [integrations](#integrations)).

### Overview

- Fast $O(k)$ dynamic route matching where $k$ is the number of segments in the request path
- Supports route parameters, including optional and wildcard variants
- Small with no external dependencies (~370 lines of code)

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

### Route Configuration

You can provide any value as the handler. The order of route matching is: static > parameter > wildcard.

#### Basic Routing

```php
// Simple GET route
$router->add('GET', '/about', 'AboutController@show');

// Multiple HTTP methods
$router->add(['GET', 'POST'], '/contact', 'ContactController@submit');

// Any HTTP method (allowed or not)
$router->add('*', '/maintenance', 'MaintenanceController@handle');

// Any allowed HTTP method
$router->add($router->allowedMethods, '/maintenance', 'MaintenanceController@handle');
```

#### Required Parameters

Matches only when the segment is present and not empty.

```php
// Required parameter
$router->add(['GET'], '/users/:id', 'UserController@profile');
// Example requests:
//   /users     -> no match
//   /users/123 -> ['id' => '123']
```

#### Optional Parameters

Matches whether the segment is present or not.

```php
// Single optional parameter
$router->add(['GET'], '/blog/:slug?', 'BlogController@view');
// Example requests:
//   /blog         -> [] (no parameters)
//   /blog/hello   -> ['slug' => 'hello']

// Chained optional parameters
$router->add(['GET'], '/archive/:year?/:month?', 'ArchiveController@list');
// Example requests:
//   /archive         -> [] (no parameters)
//   /archive/2022    -> ['year' => '2022']
//   /archive/2022/12 -> ['year' => '2022', 'month' => '12']
```

#### Wildcard Parameters

Also known as catch-all, splat, greedy, rest, or path remainder parameters.

> [!CAUTION]    
> Never use captured path segments directly in filesystem operations. Path traversal attacks can expose sensitive files or directories. Use functions like `realpath()` and restrict access to a safe base directory.

```php
// Required wildcard parameter (one or more segments)
$router->add(['GET'], '/assets/:resource+', 'AssetController@show');
// Example requests:
//   /assets                -> no match
//   /assets/logo.png       -> ['resource' => 'logo.png']
//   /assets/img/banner.jpg -> ['resource' => 'img/banner.jpg']

// Optional wildcard parameter (zero or more segments)
$router->add(['GET'], '/downloads/:file*', 'DownloadController@show');
// Example requests:
//   /downloads               -> ['file' => ''] (empty string)
//   /downloads/report.pdf    -> ['file' => 'report.pdf']
//   /downloads/docs/guide.md -> ['file' => 'docs/guide.md']
```

### Listing Routes

The router provides a convenient method for listing routes and their associated handlers.

```php
// List all routes
printf("%-8s  %-24s  %s\n", 'METHOD', 'PATTERN', 'HANDLER');
printf("%s\n", str_repeat('-', 60));
$routes = $router->list();
foreach ($routes as $route) {
    printf("%-8s  %-24s  %s\n", $route['method'], $route['pattern'], $route['handler']);
}

// List routes for specific path
printf("%s\n", str_repeat('-', 60));
$filtered = $router->list('/contact');
foreach ($filtered as $route) {
    printf("%-8s  %-24s  %s\n", $route['method'], $route['pattern'], $route['handler']);
}
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

Route caching is beneficial for classic PHP deployments where scripts are reloaded on every request. In these environments, caching routes in a PHP file allows OPcache to keep them in memory, improving performance.

For persistent environments such as Swoole or FrankenPHP in worker mode, where the application and its routes remain in memory between requests, route caching is generally unnecessary.

> [!IMPORTANT]  
> You must only provide serializable handlers such as strings or arrays. Closures and anonymous functions are not supported for route caching.
> 
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

### Extending HTTP Methods

The HTTP specification allows for custom methods.

> [!NOTE]   
> Methods must be uppercase and are only validated when adding routes.

```php
$customMethods = ['PURGE', 'REPORT'];
$router->allowedMethods = array_merge($router->allowedMethods, $customMethods);
```

You may also register a route with the fallback method to match any HTTP method.

```php
$router->add('*', '/somewhere', 'handler');
```

### Note on HEAD requests

The HTTP spec requires servers to [support both GET and HEAD methods.](http://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html#sec5.1.1)

To avoid having to manually register HEAD routes for each resource we fallback to matching an available GET route for a given resource. The PHP web SAPI transparently removes the entity body from HEAD responses so this behavior has no effect on the vast majority of users.

However, implementers using RadixRouter outside the web SAPI environment (e.g. a custom server) MUST NOT send entity bodies generated in response to HEAD requests. If you are a non-SAPI user this is your responsibility; RadixRouter has no purview to prevent you from breaking HTTP in such cases.

Finally, note that applications MAY always specify their own HEAD method route for a given resource to bypass this behavior entirely.

## Benchmarks

Most likely the router is never going to be the bottleneck of your application. Use profilers with flamegraphs instead of wasting too much time on micro-optimizations! (Unless you're into that kinda thing)

These benchmarks are single-threaded and run on an **Intel Xeon E3-1220L** (20 Watt CPU from 2011), **PHP 8.4.13**, **Debian 11**.

- **Lookups:** Measures in-memory route matching speed.
- **Mem:** Peak memory usage during the in-memory lookup benchmark.
- **Register:** Time required to setup the router and make the first lookup. (What matters for PHP SAPI)

#### Simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     2,765,751 |      109.3 |           0.124 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     2,750,915 |      169.6 |           0.172 |
|    3 |  🥉 **FastRoute (cached)** | JIT=tracing        |     1,894,780 |       86.3 |           0.137 |
|    4 |  **FastRoute**               | JIT=tracing        |     1,618,757 |      101.9 |           0.360 |
|    5 |  **RadixRouter**             | OPcache            |     1,611,397 |       45.8 |           0.172 |
|    6 |  **Symfony (cached)**        | JIT=tracing        |     1,563,357 |      279.5 |           0.185 |
|    7 |  **RadixRouter (cached)**    | OPcache            |     1,560,585 |        1.4 |           0.137 |
|    8 |  **FastRoute (cached)**      | OPcache            |     1,403,764 |        2.4 |           0.150 |
|    9 |  **Symfony**                 | JIT=tracing        |     1,385,654 |      412.4 |           1.086 |
|   10 |  **FastRoute**               | No OPcache         |     1,358,097 |      147.2 |           8.191 |
|   11 |  **RadixRouter**             | No OPcache         |     1,344,039 |       45.8 |           7.132 |
|   12 |  **FastRoute**               | OPcache            |     1,321,569 |       16.7 |           0.404 |
|   13 |  **RadixRouter (cached)**    | No OPcache         |     1,310,219 |       54.7 |           7.414 |
|   14 |  **FastRoute (cached)**      | No OPcache         |     1,250,886 |      100.0 |           7.638 |
|   15 |  **Symfony**                 | OPcache            |       799,795 |       37.2 |           1.243 |
|   16 |  **Symfony (cached)**        | OPcache            |       790,425 |        3.2 |           0.186 |
|   17 |  **Symfony (cached)**        | No OPcache         |       704,386 |      237.8 |           8.822 |
|   18 |  **Symfony**                 | No OPcache         |       702,293 |      525.8 |          11.414 |

#### Avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     1,760,107 |        1.9 |           0.127 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     1,647,918 |      376.1 |           0.478 |
|    3 |  🥉 **RadixRouter (cached)** | OPcache            |     1,084,052 |        1.9 |           0.118 |
|    4 |  **RadixRouter**             | OPcache            |     1,063,875 |      376.1 |           0.658 |
|    5 |  **RadixRouter**             | No OPcache         |       964,401 |      376.1 |           7.822 |
|    6 |  **Symfony (cached)**        | JIT=tracing        |       912,359 |        3.4 |           0.173 |
|    7 |  **RadixRouter (cached)**    | No OPcache         |       892,417 |      457.8 |           8.452 |
|    8 |  **Symfony**                 | JIT=tracing        |       885,368 |      283.4 |           8.028 |
|    9 |  **Symfony**                 | OPcache            |       574,149 |      283.4 |          12.551 |
|   10 |  **Symfony (cached)**        | OPcache            |       563,420 |        3.4 |           0.180 |
|   11 |  **Symfony (cached)**        | No OPcache         |       524,358 |      524.1 |          10.003 |
|   12 |  **Symfony**                 | No OPcache         |       508,470 |      772.0 |          23.562 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       397,365 |        2.6 |           0.180 |
|   14 |  **FastRoute**               | JIT=tracing        |       363,675 |      255.8 |           3.058 |
|   15 |  **FastRoute (cached)**      | OPcache            |       360,030 |        2.6 |           0.171 |
|   16 |  **FastRoute**               | OPcache            |       349,103 |      135.7 |           4.130 |
|   17 |  **FastRoute**               | No OPcache         |       344,468 |      266.2 |          11.170 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       323,993 |      240.9 |           8.467 |


#### Bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     1,326,742 |        1.9 |           0.123 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     1,230,323 |      300.4 |           0.476 |
|    3 |  🥉 **RadixRouter (cached)** | OPcache            |       896,624 |        1.9 |           0.174 |
|    4 |  **RadixRouter**             | OPcache            |       868,440 |      300.4 |           0.618 |
|    5 |  **RadixRouter**             | No OPcache         |       753,647 |      300.4 |           7.859 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |       698,294 |      365.3 |           9.020 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       692,757 |      120.9 |           0.189 |
|    8 |  **Symfony**                 | JIT=tracing        |       664,251 |      394.7 |           7.109 |
|    9 |  **Symfony (cached)**        | OPcache            |       431,519 |        3.5 |           0.183 |
|   10 |  **Symfony**                 | OPcache            |       423,181 |      211.6 |          11.272 |
|   11 |  **Symfony**                 | No OPcache         |       402,750 |      700.2 |          22.150 |
|   12 |  **Symfony (cached)**        | No OPcache         |       399,128 |      448.5 |           9.724 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       212,423 |        2.7 |           0.186 |
|   14 |  **FastRoute (cached)**      | OPcache            |       196,772 |        2.7 |           0.158 |
|   15 |  **FastRoute**               | JIT=tracing        |       192,315 |      256.2 |           1.228 |
|   16 |  **FastRoute**               | OPcache            |       190,840 |      141.5 |           1.646 |
|   17 |  **FastRoute**               | No OPcache         |       186,222 |      272.0 |           9.636 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       182,189 |      242.9 |           7.933 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     1,291,334 |        1.5 |           0.128 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     1,232,967 |     1357.2 |           1.187 |
|    3 |  🥉 **RadixRouter (cached)** | OPcache            |       906,168 |        1.5 |           0.128 |
|    4 |  **RadixRouter**             | OPcache            |       877,240 |     1357.2 |           1.578 |
|    5 |  **RadixRouter**             | No OPcache         |       774,213 |     1357.2 |           9.136 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |       698,309 |     1492.3 |          10.601 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       340,519 |        3.3 |           0.195 |
|    8 |  **Symfony**                 | JIT=tracing        |       333,978 |      579.9 |          18.309 |
|    9 |  **Symfony**                 | OPcache            |       265,547 |      579.9 |          27.743 |
|   10 |  **Symfony (cached)**        | OPcache            |       265,147 |        3.3 |           0.192 |
|   11 |  **Symfony**                 | No OPcache         |       251,798 |     1068.5 |          41.076 |
|   12 |  **Symfony (cached)**        | No OPcache         |       239,940 |      849.3 |          11.830 |
|   13 |  **FastRoute (cached)**      | OPcache            |       104,723 |        2.5 |           0.151 |
|   14 |  **FastRoute**               | OPcache            |       104,117 |      383.7 |           2.928 |
|   15 |  **FastRoute**               | No OPcache         |       102,844 |      514.2 |          10.522 |
|   16 |  **FastRoute (cached)**      | JIT=tracing        |       100,946 |       61.3 |           0.154 |
|   17 |  **FastRoute (cached)**      | No OPcache         |       100,777 |      508.4 |           8.842 |
|   18 |  **FastRoute**               | JIT=tracing        |        96,764 |      498.1 |           1.899 |

## Integrations

These are third-party integrations so evaluate and use them at your own discretion.

| Library | Description | Maintainer |
|---------|-------------|------------|
| [Mezzio](https://github.com/sirix777/mezzio-radixrouter) | Integration for Mezzio framework | [sirix777](https://github.com/sirix777) |
| [Yii](https://github.com/sirix777/mezzio-radixrouter) | Integration for the Yii Framework   | [sirix777](https://github.com/sirix777) |

## License

This library is licensed under the WTFPL-2.0 license. Do whatever you want with it.
