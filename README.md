# RadixRouter

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg?style=flat-square)

This library provides a minimal high-performance radix tree based HTTP request router implementation (see [benchmarks](#benchmarks) and [integrations](#integrations))

## Install

```bash
composer require wilaak/radix-router
```

Requires PHP 8.0 or newer

## Usage

Below is an example to get you started.

```PHP
$router = new Wilaak\Http\RadixRouter;

$router->add('GET', '/:name?', function ($name = 'World') {
    echo "Hello, {$name}!";
});

$method = $_SERVER['REQUEST_METHOD'];

$path = strtok($_SERVER['REQUEST_URI'], '?');
$decodedPath = rawurldecode($path);

$result = $router->lookup($method, $decodedPath);

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

You can provide any value as the handler. The order of route matching is: static > parameter > wildcard.

#### Basic Routing

```php
// Simple GET route
$router->add('GET', '/about', 'handler');

// Multiple HTTP methods
$router->add(['GET', 'POST'], '/form', 'handler');

// Any HTTP method
$router->add('*', '/fallback', 'handler');
```

#### Required Parameters

Matches only when the segment is present and not empty.

```php
// Required parameter
$router->add(['GET'], '/users/:id', 'UserController@show');
// Example requests:
//   /users     -> no match
//   /users/123 -> ['id' => '123']
```

#### Optional Parameters

Matches whether the segment is present or not.

```php
// Single optional parameter
$router->add(['GET'], '/profile/:username?', 'ProfileController@show');
// Example requests:
//   /profile      -> [] (no parameters)
//   /profile/jane -> ['username' => 'jane']

// Chained optional parameters
$router->add(['GET'], '/archive/:year?/:month?', 'ArchiveController@show');
// Example requests:
//   /archive         -> [] (no parameters)
//   /archive/1974    -> ['year' => '1974']
//   /archive/1974/06 -> ['year' => '1974', 'month' => '06']
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
GET       /assets/:resource+        AssetController@show
GET       /downloads/:file*         DownloadController@show
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
            $allowedMethods = $router->methods($decodedPath);
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

### Handling HEAD Requests 

If you are running outside of a traditional SAPI environment (like a custom server), ensure your GET routes also respond correctly to HEAD requests. Responses to HEAD requests must not include a message body.

Typically, this is achieved by internally treating HEAD requests as GET requests, but only returning the headers and omitting the response body. However, you should still allow developers to explicitly register HEAD routes when custom behavior is needed.

### Extending HTTP Methods

The HTTP specification allows for custom methods. You can extend the list of allowed methods by modifying the `allowedMethods` property.

By default, the router supports the following methods: GET, POST, PUT, DELETE, PATCH, OPTIONS, HEAD.

> [!NOTE]   
> Methods must be uppercase and are only validated when adding routes.

```php
$router->allowedMethods = array_merge(
    $router->allowedMethods,
    ['PURGE', 'REPORT'] // Add custom methods here
);
```

You may also register a route with the fallback `*` method to match any HTTP method.

```php
$router->add('*', '/somewhere', 'handler');

// Will return all methods in the allowedMethods list
$router->methods('/somewhere');
```

## Benchmarks

All benchmarks are single-threaded and run on an Intel Xeon Gold 6138, PHP 8.4.13.

- Lookups: Measures in-memory route matching speed.
- Mem: Peak memory usage during the in-memory lookup benchmark.
- Register: Time required to setup the router and make the first lookup.

#### Simple (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 RadixRouter (cached) | JIT=tracing        |     3,947,966 |       87.6 |           0.107 |
|    2 |  🥈 RadixRouter        | JIT=tracing        |     3,582,173 |      165.1 |           0.161 |
|    3 |  🥉 FastRoute (cached) | JIT=tracing        |     2,900,319 |       86.6 |           0.090 |
|    4 |  FastRoute               | JIT=tracing        |     2,823,239 |      101.9 |           0.316 |
|    5 |  RadixRouter (cached)    | OPcache            |     2,542,350 |        1.7 |           0.075 |
|    6 |  RadixRouter             | OPcache            |     2,375,520 |       45.8 |           0.146 |
|    7 |  Symfony (cached)        | JIT=tracing        |     2,243,122 |      279.7 |           0.164 |
|    8 |  RadixRouter             | No OPcache         |     2,207,163 |       45.8 |           5.136 |
|    9 |  FastRoute (cached)      | OPcache            |     2,194,923 |        2.7 |           0.092 |
|   10 |  Symfony                 | JIT=tracing        |     2,173,067 |      412.4 |           0.984 |
|   11 |  FastRoute               | OPcache            |     2,160,999 |       16.7 |           0.236 |
|   12 |  RadixRouter (cached)    | No OPcache         |     2,047,785 |       54.7 |           4.805 |
|   13 |  FastRoute               | No OPcache         |     2,038,225 |      147.2 |           5.502 |
|   14 |  FastRoute (cached)      | No OPcache         |     1,941,551 |      100.1 |           4.795 |
|   15 |  Symfony (cached)        | OPcache            |     1,381,314 |        3.4 |           0.111 |
|   16 |  Symfony                 | OPcache            |     1,335,327 |       37.2 |           0.723 |
|   17 |  Symfony (cached)        | No OPcache         |     1,262,745 |      237.9 |           6.872 |
|   18 |  Symfony                 | No OPcache         |     1,259,306 |      525.9 |           7.423 |

#### Avatax (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 RadixRouter (cached) | JIT=tracing        |     2,407,391 |        2.2 |           0.077 |
|    2 |  🥈 RadixRouter        | JIT=tracing        |     2,232,671 |      376.1 |           0.528 |
|    3 |  🥉 RadixRouter (cached) | OPcache            |     1,689,140 |        2.2 |           0.078 |
|    4 |  RadixRouter             | OPcache            |     1,620,555 |      376.1 |           0.431 |
|    5 |  RadixRouter             | No OPcache         |     1,446,815 |      376.1 |           4.916 |
|    6 |  RadixRouter (cached)    | No OPcache         |     1,432,740 |      457.8 |           5.373 |
|    7 |  Symfony (cached)        | JIT=tracing        |     1,402,660 |        3.7 |           0.150 |
|    8 |  Symfony                 | JIT=tracing        |     1,326,572 |      283.4 |           4.659 |
|    9 |  Symfony                 | OPcache            |       986,058 |      283.4 |           6.992 |
|   10 |  Symfony (cached)        | OPcache            |       965,090 |        3.7 |           0.144 |
|   11 |  Symfony                 | No OPcache         |       900,080 |      772.1 |          13.980 |
|   12 |  Symfony (cached)        | No OPcache         |       821,235 |      524.2 |           6.548 |
|   13 |  FastRoute (cached)      | JIT=tracing        |       688,338 |        2.8 |           0.096 |
|   14 |  FastRoute               | JIT=tracing        |       681,499 |      255.8 |           2.011 |
|   15 |  FastRoute (cached)      | OPcache            |       612,585 |        2.8 |           0.093 |
|   16 |  FastRoute               | No OPcache         |       593,211 |      266.2 |           7.345 |
|   17 |  FastRoute               | OPcache            |       592,629 |      135.7 |           2.354 |
|   18 |  FastRoute (cached)      | No OPcache         |       577,026 |      240.9 |           6.097 |

#### Bitbucket (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 RadixRouter (cached) | JIT=tracing        |     1,835,580 |        2.2 |           0.114 |
|    2 |  🥈 RadixRouter        | JIT=tracing        |     1,597,981 |      300.4 |           0.323 |
|    3 |  🥉 RadixRouter (cached) | OPcache            |     1,316,416 |        2.2 |           0.081 |
|    4 |  RadixRouter             | OPcache            |     1,174,231 |      300.4 |           0.408 |
|    5 |  RadixRouter             | No OPcache         |     1,120,737 |      300.4 |           4.970 |
|    6 |  RadixRouter (cached)    | No OPcache         |     1,119,639 |      365.3 |           5.356 |
|    7 |  Symfony (cached)        | JIT=tracing        |     1,026,170 |      121.2 |           0.120 |
|    8 |  Symfony                 | JIT=tracing        |     1,007,328 |      394.7 |           4.634 |
|    9 |  Symfony (cached)        | OPcache            |       753,977 |        3.8 |           0.118 |
|   10 |  Symfony                 | OPcache            |       752,520 |      211.6 |           6.341 |
|   11 |  Symfony                 | No OPcache         |       692,634 |      700.2 |          13.448 |
|   12 |  Symfony (cached)        | No OPcache         |       680,563 |      448.6 |           6.649 |
|   13 |  FastRoute (cached)      | JIT=tracing        |       365,622 |        3.0 |           0.101 |
|   14 |  FastRoute               | JIT=tracing        |       363,010 |      256.2 |           1.016 |
|   15 |  FastRoute (cached)      | OPcache            |       333,386 |        3.0 |           0.090 |
|   16 |  FastRoute               | OPcache            |       324,212 |      141.5 |           1.112 |
|   17 |  FastRoute               | No OPcache         |       320,968 |      272.0 |           6.109 |
|   18 |  FastRoute (cached)      | No OPcache         |       316,568 |      242.9 |           5.885 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  🏆 RadixRouter (cached) | JIT=tracing        |     1,802,250 |        1.8 |           0.122 |
|    2 |  🥈 RadixRouter        | JIT=tracing        |     1,561,931 |     1357.2 |           0.955 |
|    3 |  🥉 RadixRouter (cached) | OPcache            |     1,329,142 |        1.8 |           0.083 |
|    4 |  RadixRouter             | OPcache            |     1,161,429 |     1357.2 |           0.923 |
|    5 |  RadixRouter             | No OPcache         |     1,106,722 |     1357.2 |           5.365 |
|    6 |  RadixRouter (cached)    | No OPcache         |     1,007,902 |     1492.3 |           7.284 |
|    7 |  Symfony (cached)        | JIT=tracing        |       533,848 |        3.5 |           0.185 |
|    8 |  Symfony                 | JIT=tracing        |       513,690 |      579.9 |          11.690 |
|    9 |  Symfony (cached)        | OPcache            |       428,123 |        3.5 |           0.154 |
|   10 |  Symfony                 | OPcache            |       424,982 |      579.9 |          15.855 |
|   11 |  Symfony                 | No OPcache         |       410,151 |     1068.5 |          24.400 |
|   12 |  Symfony (cached)        | No OPcache         |       400,380 |      849.4 |           7.467 |
|   13 |  FastRoute (cached)      | JIT=tracing        |       208,110 |       61.5 |           0.136 |
|   14 |  FastRoute               | JIT=tracing        |       204,434 |      498.1 |           1.538 |
|   15 |  FastRoute               | OPcache            |       190,536 |      383.7 |           2.190 |
|   16 |  FastRoute (cached)      | OPcache            |       188,269 |        2.8 |           0.098 |
|   17 |  FastRoute               | No OPcache         |       176,974 |      514.2 |           7.320 |
|   18 |  FastRoute (cached)      | No OPcache         |       167,053 |      508.4 |           5.631 |

## Integrations

- [Mezzio](https://github.com/sirix777/mezzio-radixrouter) - RadixRouter integration for Mezzio framework

## License

This library is licensed under the **WTFPL-2.0** license. Do whatever you want with it.
