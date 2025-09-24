# RadixRouter

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg?style=flat-square)

This library provides a minimal high-performance radix tree based HTTP request router implementation (see [benchmarks](#benchmarks) and [integrations](#integrations))

## Install

Install with composer:

    composer require wilaak/radix-router

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
$router->add(['GET'], '/about', 'AboutController@show');

// Multiple HTTP methods
$router->add(['GET', 'POST'], '/form', 'FormController@handle');
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

## Benchmarks

All benchmarks are single-threaded and run on an Intel Xeon Gold 6138, PHP 8.4.11.


- **Lookups:** Measures in-memory route matching speed.
- **Mem:** Peak memory usage during the in-memory lookup benchmark.
- **Register:** Time required to setup the router and make the first lookup.

#### Simple (33 routes)
| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     3,828,287 |       87.4 |           0.107 |
|    2 |  **RadixRouter**        | JIT=tracing        |     3,590,410 |      165.1 |           0.159 |
|    3 |  **FastRoute (cached)** | JIT=tracing        |     2,820,789 |       85.1 |           0.131 |
|    4 |  **FastRoute**               | JIT=tracing        |     2,793,876 |      101.9 |           0.339 |
|    5 |  **RadixRouter (cached)**    | OPcache            |     2,544,001 |        1.4 |           0.074 |
|    6 |  **RadixRouter**             | OPcache            |     2,409,656 |       45.8 |           0.166 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |     2,221,679 |      279.5 |           0.168 |
|    8 |  **RadixRouter**             | No OPcache         |     2,205,480 |       45.8 |           5.082 |
|    9 |  **FastRoute (cached)**      | OPcache            |     2,196,745 |        1.2 |           0.127 |
|   10 |  **FastRoute**               | OPcache            |     2,150,099 |       16.7 |           0.327 |
|   11 |  **Symfony**                 | JIT=tracing        |     2,128,204 |      412.4 |           1.032 |
|   12 |  **RadixRouter (cached)**    | No OPcache         |     2,074,528 |       54.7 |           4.645 |
|   13 |  **FastRoute**               | No OPcache         |     2,069,355 |      147.9 |           6.536 |
|   14 |  **FastRoute (cached)**      | No OPcache         |     1,948,277 |       35.1 |           4.868 |
|   15 |  **Symfony (cached)**        | OPcache            |     1,383,723 |        3.2 |           0.120 |
|   16 |  **Symfony**                 | OPcache            |     1,336,442 |       37.2 |           1.047 |
|   17 |  **Symfony**                 | No OPcache         |     1,254,210 |      526.9 |           7.384 |
|   18 |  **Symfony (cached)**        | No OPcache         |     1,243,005 |      238.5 |           5.619 |

#### Avatax (256 routes)
| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     2,311,676 |        1.9 |           0.080 |
|    2 |  **RadixRouter**        | JIT=tracing        |     2,222,923 |      376.1 |           0.528 |
|    3 |  **RadixRouter (cached)** | OPcache            |     1,667,738 |        1.9 |           0.077 |
|    4 |  **RadixRouter**             | OPcache            |     1,566,446 |      376.1 |           0.488 |
|    5 |  **RadixRouter**             | No OPcache         |     1,463,213 |      376.1 |           4.835 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,427,933 |      457.8 |           5.423 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |     1,311,440 |        3.4 |           0.119 |
|    8 |  **Symfony**                 | JIT=tracing        |     1,271,524 |      283.4 |           5.482 |
|    9 |  **Symfony (cached)**        | OPcache            |       957,374 |        3.4 |           0.163 |
|   10 |  **Symfony**                 | OPcache            |       937,518 |      283.4 |           7.205 |
|   11 |  **Symfony (cached)**        | No OPcache         |       872,969 |      524.8 |           7.074 |
|   12 |  **Symfony**                 | No OPcache         |       864,233 |      773.1 |          15.031 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       664,962 |        1.3 |           0.132 |
|   14 |  **FastRoute**               | JIT=tracing        |       652,866 |      255.8 |           1.985 |
|   15 |  **FastRoute (cached)**      | OPcache            |       600,333 |        1.3 |           0.088 |
|   16 |  **FastRoute**               | OPcache            |       580,069 |      135.7 |           3.166 |
|   17 |  **FastRoute**               | No OPcache         |       572,149 |      266.9 |           6.885 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       554,882 |      175.9 |           5.523 |

#### Bitbucket (177 routes)
| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     1,797,640 |        1.9 |           0.081 |
|    2 |  **RadixRouter**        | JIT=tracing        |     1,717,261 |      300.4 |           0.355 |
|    3 |  **RadixRouter (cached)** | OPcache            |     1,274,797 |        1.9 |           0.123 |
|    4 |  **RadixRouter**             | OPcache            |     1,240,628 |      300.4 |           0.539 |
|    5 |  **RadixRouter**             | No OPcache         |     1,181,062 |      300.4 |           4.883 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,094,547 |      365.3 |           5.447 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       960,303 |      120.9 |           0.176 |
|    8 |  **Symfony**                 | JIT=tracing        |       934,371 |      394.7 |           4.479 |
|    9 |  **Symfony (cached)**        | OPcache            |       720,018 |        3.5 |           0.123 |
|   10 |  **Symfony**                 | OPcache            |       697,684 |      211.6 |           6.311 |
|   11 |  **Symfony (cached)**        | No OPcache         |       654,557 |      449.1 |           6.225 |
|   12 |  **Symfony**                 | No OPcache         |       648,176 |      701.3 |          13.142 |
|   13 |  **FastRoute**               | JIT=tracing        |       355,223 |      256.2 |           0.933 |
|   14 |  **FastRoute (cached)**      | JIT=tracing        |       351,762 |        1.4 |           0.149 |
|   15 |  **FastRoute (cached)**      | OPcache            |       323,680 |        1.4 |           0.143 |
|   16 |  **FastRoute**               | OPcache            |       313,133 |      141.5 |           1.700 |
|   17 |  **FastRoute**               | No OPcache         |       312,354 |      272.6 |           5.932 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       303,734 |      177.9 |           5.380 |

#### Huge (500 routes)

Randomly generated routes containing at least 1 dynamic segment with depth ranging from 1 to 6 segments.

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     1,746,230 |        1.5 |           0.123 |
|    2 |  **RadixRouter**        | JIT=tracing        |     1,531,221 |     1357.2 |           1.332 |
|    3 |  **RadixRouter (cached)** | OPcache            |     1,320,503 |        1.5 |           0.083 |
|    4 |  **RadixRouter**             | OPcache            |     1,183,485 |     1357.2 |           1.050 |
|    5 |  **RadixRouter**             | No OPcache         |     1,112,042 |     1357.2 |           5.416 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,019,516 |     1492.3 |           6.839 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       444,627 |        3.3 |           0.180 |
|    8 |  **Symfony**                 | JIT=tracing        |       443,156 |      579.9 |          11.341 |
|    9 |  **Symfony (cached)**        | OPcache            |       383,310 |        3.3 |           0.179 |
|   10 |  **Symfony**                 | OPcache            |       369,934 |      579.9 |          16.051 |
|   11 |  **Symfony**                 | No OPcache         |       365,701 |     1069.6 |          23.858 |
|   12 |  **Symfony (cached)**        | No OPcache         |       360,477 |      850.0 |           7.310 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       213,049 |       60.0 |           0.138 |
|   14 |  **FastRoute**               | JIT=tracing        |       206,498 |      498.1 |           1.688 |
|   15 |  **FastRoute (cached)**      | OPcache            |       188,994 |        1.3 |           0.098 |
|   16 |  **FastRoute**               | OPcache            |       182,994 |      383.7 |           1.713 |
|   17 |  **FastRoute**               | No OPcache         |       181,253 |      514.9 |           7.278 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       176,026 |      443.5 |           5.530 |

## Integrations

- [Mezzio](https://github.com/sirix777/mezzio-radixrouter) - RadixRouter integration for Mezzio framework

## License

This library is licensed under the **WTFPL-2.0** license. Do whatever you want with it.
