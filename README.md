# RadixRouter

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg)

This library provides a minimal radix tree based HTTP request router implementation (see [benchmarks](#benchmarks) and [integrations](#integrations))

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.0 or newer

## Usage

Below is a basic usage example to get you started.

```PHP
$router = new Wilaak\Http\RadixRouter;

$router->add(['GET'], '/', function () {
    echo "Welcome!";
});

$router->add(['GET'], '/hello/:name?', function ($name = 'World') {
    echo "Hello, $name!";
});

// Get the request HTTP method (GET, POST, etc.)
$method = $_SERVER['REQUEST_METHOD'];

// Get the request path, removing any query parameters (?foo=bar)
$path = strtok($_SERVER['REQUEST_URI'], '?');

$result = $router->lookup($method, rawurldecode($path));

switch ($result['code']) {
    case 200:
        // Route found, call the handler with parameters
        $result['handler'](...$result['params']);
        break;

    case 404:
        // No matching route found
        http_response_code(404);
        echo '404 Not Found';
        break;

    case 405:
        // Method not allowed for this route
        header('Allow: ' . implode(',', $result['allowed_methods']));
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

### Route Configuration

You can provide any value as the handler. The order of route matching is: static > parameter > wildcard. Below is an example showing the different ways to define routes.

> [!NOTE]   
> Patterns are normalized meaning `/about` and `/about/` are treated as the same route. Read more in [path correction](#path-correction).

```php
// Static route
$router->add(['GET'], '/about', 'AboutController@show');

// Multiple HTTP methods
$router->add(['GET', 'POST'], '/form', 'FormController@handle');

// Required parameter
$router->add(['GET'], '/users/:id', 'UserController@show');
// Example requests:
//   /users     -> no match
//   /users/123 -> ['id' => '123']

// Optional parameter
$router->add(['GET'], '/profile/:username?', 'ProfileController@show');
// Example requests:
//   /profile      -> [] (empty)
//   /profile/jane -> ['username' => 'jane'])

// Chained optional parameters
$router->add(['GET'], '/archive/:year?/:month?', 'ArchiveController@show');
// Example requests:
//   /archive         -> [] (empty)
//   /archive/1974    -> ['year' => '1974']
//   /archive/1974/06 -> ['year' => '1974', 'month' => '06']

// Wildcard (Catch-All) parameter
$router->add(['GET'], '/files/:path*', 'FileController@show');
// Example requests:
//   /files                  -> ['path' => ''] (empty string)
//   /files/readme.txt       -> ['path' => 'readme.txt'])
//   /files/images/photo.jpg -> ['path' => 'images/photo.jpg']
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
$filtered = $router->list('/form');
foreach ($filtered as $route) {
    printf("%-8s  %-24s  %s\n", $route['method'], $route['pattern'], $route['handler']);
}
```

Example Output:

```
METHOD    PATTERN                   HANDLER
------------------------------------------------------------
GET       /about                    AboutController@show
GET       /archive/:year?/:month?   ArchiveController@show
GET       /files/:path*             FileController@show
GET       /form                     FormController@handle
POST      /form                     FormController@handle
GET       /profile/:username?       ProfileController@show
GET       /users/:id                UserController@show
------------------------------------------------------------
GET       /form                     FormController@handle
POST      /form                     FormController@handle
```


## Advanced Usage 

### Route Caching

By storing your routes in a PHP file you let OPcache keep it in memory between requests.

> [!IMPORTANT]  
> You must only provide serializable handlers such as strings or arrays. Closures and anonymous functions are not supported for route caching.

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
$router->tree   = $routes['tree'];
$router->static = $routes['static'];
```

### Path Correction

Trailing slashes are ignored, meaning both `/about` and `/about/` will match. While this is common behavior in most routers, you may prefer enforcing a single canonical URL. This can help prevent duplicate content for search engines, improve caching efficiency and simplify analytics.

> [!CAUTION]    
> Never use decoded paths (e.g from `rawurldecode()`) directly in HTTP headers. Decoded paths may contain dangerous characters (like `%0A` for newlines) that can lead to header injection vulnerabilities. Always use the original, encoded path when performing redirects.


#### Normalizing Consecutive Slashes

This finds multiple consecutive slashes in the path and replaces them with a single slash. For example, accessing `/user//123` will redirect to `/user/123`.

```php
if (str_contains($path, '//')) {
    $path = preg_replace('#/+#', '/', $path);
    header("Location: {$path}", true, 301);
    exit;
}
```

#### Trailing Slash Convention

This ensures the request path’s trailing slash matches the route pattern. For example, accessing `/files/:folder` when `/files/:folder/` is registered will redirect to the trailing slash.

```php
// ...existing code...

switch ($result['code']) {
    case 200:
        // Follow trailing slash convention of the route pattern
        $trailing = str_ends_with($result['pattern'], '/');
        if (str_ends_with($path, '/') !== $trailing) {
            $canonical = rtrim($path, '/');
            if ($trailing) {
                $canonical = "{$canonical}/";
            }
            header("Location: {$canonical}", true, 301);
            break;
        }

        // ...existing code...
        break;

    case 404:
        // ...existing code...
        break;

    case 405:
        // ...existing code...
        break;
}
```

### Handling OPTIONS Requests and CORS

For OPTIONS requests you should always inform the client which HTTP methods are allowed for the path by setting the Allow header. You can enable this behavior by upgrading a 405 response and injecting the headers for registered routes.

> [!NOTE]   
> For Integrators: One might wish to modify automatic responses to OPTIONS requests, e.g. to support [CORS Preflight requests](https://developer.mozilla.org/en-US/docs/Glossary/Preflight_request) or to set other custom headers. You should handle this via configuration or middleware in your integration.

```php
// ...existing code...

switch ($result['code']) {
    case 200:
        // ...existing code...

        // Add necessary headers if this is an OPTIONS request
        if ($method === 'OPTIONS') {
            $allowedMethods = $router->methods($path);
            header('Allow: ' . implode(',', $allowedMethods));
            // Add CORS headers here if needed, e.g.:
            // if (isset($_SERVER['HTTP_ORIGIN'])) {
            //     header('Access-Control-Allow-Origin: *');
            //     header('Access-Control-Allow-Headers: ...');
            //     header('Access-Control-Allow-Methods: ...');
            // }
        }

        // ...existing code...
        break;

    case 404:
        // ...existing code...
        break;

    case 405:
        // Method not allowed for this route
        header('Allow: ' . implode(',', $result['allowed_methods']));
        if ($method === 'OPTIONS') {
            http_response_code(204);
            // Add CORS headers here if needed
            break;
        }
        http_response_code(405);
        echo '405 Method Not Allowed';
        break;
}
```

### Extending HTTP Methods

You may wish to support more HTTP methods than the default ones, for example if you are going to create a WebDAV server.

> [!NOTE]   
> Methods must be uppercase and are only validated when adding routes.

```php
$webdavMethods = [
    'PROPFIND',
    'PROPPATCH',
    'MKCOL',
    'COPY',
    'MOVE',
    'LOCK',
    'UNLOCK',
    'REPORT',
    'MKACTIVITY',
    'CHECKOUT',
    'MERGE'
];

// Add support for WebDAV
array_merge(
    $router->allowedMethods,
    $webdavMethods
);
```

### Handling HEAD Requests 

If you are running outside of a traditional SAPI environment (like a custom server), ensure your GET routes also respond correctly to HEAD requests. Responses to HEAD requests must not include a message body.

This is usually done by converting HEAD to GET and returning just the headers, no body.

## Benchmarks

All benchmarks are single-threaded and run on an Intel Xeon Gold 6138, PHP 8.4.11.

- **Lookups:** Measures in-memory route matching speed.
- **Mem:** Peak memory usage during the in-memory lookup benchmark.
- **Register:** Time required to setup the router and make the first lookup. (what matters for traditional SAPI environments)


#### Simple App (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     3,890,120 |      424.9 |           0.006 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     3,649,340 |      499.5 |           0.042 |
|    3 |  🥉 **FastRoute (cached)** | JIT=tracing        |     2,815,138 |      446.6 |           0.033 |
|    4 |  **FastRoute**               | JIT=tracing        |     2,739,099 |      439.5 |           0.211 |
|    5 |  **RadixRouter (cached)**    | OPcache            |     2,519,738 |      339.2 |           0.007 |
|    6 |  **RadixRouter**             | OPcache            |     2,403,765 |      384.2 |           0.050 |
|    7 |  **RadixRouter**             | No OPcache         |     2,273,260 |     1453.9 |           0.045 |
|    8 |  **Symfony (cached)**        | JIT=tracing        |     2,231,400 |      520.4 |           0.052 |
|    9 |  **FastRoute (cached)**      | OPcache            |     2,213,228 |      339.0 |           0.022 |
|   10 |  **FastRoute**               | OPcache            |     2,164,663 |      354.3 |           0.159 |
|   11 |  **RadixRouter (cached)**    | No OPcache         |     2,156,953 |     1465.6 |           0.108 |
|   12 |  **Symfony**                 | JIT=tracing        |     2,138,630 |      646.3 |           0.616 |
|   13 |  **FastRoute**               | No OPcache         |     1,985,828 |     1554.5 |           0.673 |
|   14 |  **FastRoute (cached)**      | No OPcache         |     1,877,496 |     1442.6 |           0.210 |
|   15 |  **Symfony (cached)**        | OPcache            |     1,368,758 |      340.9 |           0.045 |
|   16 |  **Symfony**                 | OPcache            |     1,358,160 |      374.8 |           0.777 |
|   17 |  **Symfony**                 | No OPcache         |     1,251,776 |     1934.8 |           3.023 |
|   18 |  **Symfony (cached)**        | No OPcache         |     1,246,070 |     1648.9 |           1.152 |

#### Avatax API (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     2,326,472 |      339.7 |           0.006 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     2,189,298 |      726.6 |           0.358 |
|    3 |  🥉 **Symfony (cached)**   | JIT=tracing        |     1,728,377 |      347.9 |           0.069 |
|    4 |  **RadixRouter (cached)**    | OPcache            |     1,682,310 |      339.7 |           0.009 |
|    5 |  **RadixRouter**             | OPcache            |     1,579,697 |      726.6 |           0.332 |
|    6 |  **RadixRouter**             | No OPcache         |     1,504,520 |     1820.5 |           0.333 |
|    7 |  **RadixRouter (cached)**    | No OPcache         |     1,449,711 |     1895.1 |           0.817 |
|    8 |  **Symfony**                 | JIT=tracing        |     1,291,156 |      621.0 |           4.687 |
|    9 |  **Symfony (cached)**        | OPcache            |     1,175,087 |      347.9 |           0.069 |
|   10 |  **Symfony**                 | OPcache            |       934,590 |      621.0 |           7.071 |
|   11 |  **Symfony**                 | No OPcache         |       866,989 |     2205.2 |           9.608 |
|   12 |  **Symfony (cached)**        | No OPcache         |       858,352 |     1967.4 |           1.911 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       676,218 |      339.1 |           0.032 |
|   14 |  **FastRoute**               | JIT=tracing        |       651,972 |      593.4 |           2.508 |
|   15 |  **FastRoute (cached)**      | OPcache            |       605,267 |      339.1 |           0.033 |
|   16 |  **FastRoute**               | OPcache            |       575,918 |      473.3 |           3.179 |
|   17 |  **FastRoute**               | No OPcache         |       569,965 |     1697.7 |           2.441 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       552,892 |     1608.3 |           0.557 |

#### Bitbucket API (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     1,786,478 |      339.7 |           0.013 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     1,664,734 |      652.0 |           0.389 |
|    3 |  🥉 **RadixRouter (cached)** | OPcache            |     1,319,106 |      339.7 |           0.014 |
|    4 |  **RadixRouter**             | OPcache            |     1,244,529 |      652.0 |           0.523 |
|    5 |  **RadixRouter**             | No OPcache         |     1,191,630 |     1742.1 |           0.311 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,143,738 |     1796.6 |           0.727 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       951,635 |      341.3 |           0.087 |
|    8 |  **Symfony**                 | JIT=tracing        |       939,828 |      732.3 |           4.434 |
|    9 |  **Symfony (cached)**        | OPcache            |       723,066 |      341.3 |           0.075 |
|   10 |  **Symfony**                 | OPcache            |       708,906 |      549.2 |           5.975 |
|   11 |  **Symfony**                 | No OPcache         |       678,972 |     2129.6 |           8.741 |
|   12 |  **Symfony (cached)**        | No OPcache         |       676,660 |     1880.1 |           1.661 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       358,298 |      339.2 |           0.045 |
|   14 |  **FastRoute**               | JIT=tracing        |       357,987 |      593.8 |           1.044 |
|   15 |  **FastRoute (cached)**      | OPcache            |       333,705 |      339.2 |           0.041 |
|   16 |  **FastRoute**               | OPcache            |       327,091 |      479.1 |           1.230 |
|   17 |  **FastRoute**               | No OPcache         |       317,329 |     1699.7 |           1.349 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       310,684 |     1605.8 |           0.571 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 **RadixRouter (cached)** | JIT=tracing        |     1,769,118 |      339.3 |           0.012 |
|    2 |  🥈 **RadixRouter**        | JIT=tracing        |     1,520,189 |     1724.6 |           0.698 |
|    3 |  🥉 **RadixRouter (cached)** | OPcache            |     1,298,703 |      339.3 |           0.009 |
|    4 |  **RadixRouter**             | OPcache            |     1,199,993 |     1724.6 |           0.973 |
|    5 |  **RadixRouter**             | No OPcache         |     1,131,474 |     2834.5 |           0.805 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,021,850 |     2945.6 |           2.254 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       451,571 |      341.0 |           0.078 |
|    8 |  **Symfony**                 | JIT=tracing        |       433,689 |      917.5 |          12.106 |
|    9 |  **Symfony (cached)**        | OPcache            |       382,095 |      341.0 |           0.079 |
|   10 |  **Symfony**                 | OPcache            |       373,327 |      917.5 |          15.708 |
|   11 |  **Symfony**                 | No OPcache         |       355,714 |     2517.6 |          18.597 |
|   12 |  **Symfony (cached)**        | No OPcache         |       352,571 |     2300.6 |           2.864 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       207,736 |      397.8 |           0.042 |
|   14 |  **FastRoute**               | JIT=tracing        |       203,890 |      835.7 |           1.320 |
|   15 |  **FastRoute (cached)**      | OPcache            |       191,926 |      339.0 |           0.041 |
|   16 |  **FastRoute**               | OPcache            |       184,481 |      721.3 |           2.294 |
|   17 |  **FastRoute**               | No OPcache         |       183,514 |     1961.7 |           1.842 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       179,370 |     1891.1 |           1.193 |

## Integrations

- [Mezzio](https://github.com/sirix777/mezzio-radixrouter) - RadixRouter integration for Mezzio framework

## License

This library is licensed under the **WTFPL-2.0** license. Do whatever you want with it.
