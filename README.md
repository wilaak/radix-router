# RadixRouter

![License](https://img.shields.io/packagist/l/wilaak/radix-router.svg)
![Downloads](https://img.shields.io/packagist/dt/wilaak/radix-router.svg)

This library provides a lightweight radix tree based HTTP request router implementation (see [benchmarks](#benchmarks) and [integrations](#integrations))

### Features

- High-performance dynamic route matching using a radix tree
- Named route parameters with optional and wildcard types
- Canonical URL redirects; don't worry about trailing slashes or SEO

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.0 or newer

## Usage

Below is an example to get you started.

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
$path = rawurldecode(
    strtok($_SERVER['REQUEST_URI'], '?')
);

$result = $router->lookup($method, $path);

switch ($result['code']) {
    case 200:
        // Optionally redirect to the same style as route pattern
        $canonical = $router->canonicalize($path, $result);
        if ($path !== $canonical) {
            // Note: This removes any query parameters
            header("Location: $canonical", true, 301);
            exit;
        }
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
> Patterns are normalized meaning `/about` and `/about/` will be treated as the same.

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
//   /profile      -> (empty)
//   /profile/jane -> ['username' => 'jane'])

// Chained optional parameters
$router->add(['GET'], '/archive/:year?/:month?', 'ArchiveController@show');
// Example requests:
//   /archive         -> (empty)
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

The router provides a convenient method for listing all registered routes and their associated handlers.

```php
$routes = $router->list();
foreach ($routes as $route) {
    printf("%s %s %s\n", $route['method'], $route['pattern'], $route['handler']);
}
```

Example Output:

```
GET     /files/:path*            FileController@show
GET     /users                   UserController@index
POST    /users                   UserController@store
DELETE  /users/:id               UserController@delete
GET     /users/:id               UserController@show
GET     /archive/:year?/:month?  ArchiveController@show
GET     /profile/:username?      ProfileController@show
GET     /about                   AboutController@show
```

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

### Handling HEAD Requests 

If you are running outside of a traditional SAPI environment, ensure your GET routes also respond correctly to HEAD requests. Responses to HEAD requests must not include a message body.

## Benchmarks

All benchmarks are single-threaded and run on an Intel Xeon Gold 6138, PHP 8.4.11.

- **Register:** Time required to setup the router and register all routes.
- **Lookups:** Measures in-memory route matching speed.
- **Mem:** Peak memory usage during the in-memory lookup benchmark.


#### Simple App (33 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     3,930,128 |      427.9 |           0.014 |
|    2 |  **RadixRouter**        | JIT=tracing        |     3,615,382 |      503.1 |           0.081 |
|    3 |  **FastRoute (cached)** | JIT=tracing        |     2,891,742 |      402.6 |           0.039 |
|    4 |  **FastRoute**               | JIT=tracing        |     2,749,258 |      441.8 |           0.336 |
|    5 |  **RadixRouter (cached)**    | OPcache            |     2,549,817 |      342.8 |           0.010 |
|    6 |  **RadixRouter**             | OPcache            |     2,386,717 |      387.8 |           0.061 |
|    7 |  **RadixRouter**             | No OPcache         |     2,275,031 |     1711.6 |           0.068 |
|    8 |  **Symfony (cached)**        | JIT=tracing        |     2,208,278 |      718.4 |           0.076 |
|    9 |  **FastRoute (cached)**      | OPcache            |     2,190,905 |      342.5 |           0.049 |
|   10 |  **FastRoute**               | OPcache            |     2,150,259 |      356.6 |           0.319 |
|   11 |  **Symfony**                 | JIT=tracing        |     2,146,500 |      752.3 |           1.567 |
|   12 |  **RadixRouter (cached)**    | No OPcache         |     2,105,640 |     1723.3 |           0.165 |
|   13 |  **FastRoute**               | No OPcache         |     2,044,461 |     1826.8 |           0.700 |
|   14 |  **FastRoute (cached)**      | No OPcache         |     1,929,935 |     1700.2 |           0.207 |
|   15 |  **Symfony (cached)**        | OPcache            |     1,345,964 |      343.2 |           0.088 |
|   16 |  **Symfony**                 | OPcache            |     1,315,504 |      377.1 |           1.182 |
|   17 |  **Symfony**                 | No OPcache         |     1,264,695 |     2191.0 |           4.604 |
|   18 |  **Symfony (cached)**        | No OPcache         |     1,243,142 |     1921.2 |           1.101 |

#### Avatax API (256 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     2,331,407 |      343.2 |           0.009 |
|    2 |  **RadixRouter**        | JIT=tracing        |     2,210,130 |      730.2 |           0.472 |
|    3 |  **RadixRouter (cached)** | OPcache            |     1,670,049 |      343.2 |           0.008 |
|    4 |  **RadixRouter**             | OPcache            |     1,575,314 |      730.2 |           0.593 |
|    5 |  **RadixRouter**             | No OPcache         |     1,503,713 |     2078.1 |           0.581 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,433,009 |     2150.5 |           1.357 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |     1,270,822 |      343.5 |           0.078 |
|    8 |  **Symfony**                 | JIT=tracing        |     1,258,453 |      623.4 |           7.931 |
|    9 |  **Symfony (cached)**        | OPcache            |       956,895 |      343.5 |           0.065 |
|   10 |  **Symfony**                 | OPcache            |       921,753 |      623.4 |          11.193 |
|   11 |  **Symfony (cached)**        | No OPcache         |       877,930 |     2231.7 |           1.888 |
|   12 |  **Symfony**                 | No OPcache         |       859,936 |     2461.4 |          12.258 |
|   13 |  **FastRoute (cached)**      | JIT=tracing        |       675,302 |      342.7 |           0.039 |
|   14 |  **FastRoute**               | JIT=tracing        |       657,222 |      595.7 |           2.783 |
|   15 |  **FastRoute (cached)**      | OPcache            |       589,313 |      342.7 |           0.039 |
|   16 |  **FastRoute**               | OPcache            |       580,267 |      475.6 |           3.670 |
|   17 |  **FastRoute**               | No OPcache         |       574,879 |     1970.0 |           2.463 |
|   18 |  **FastRoute (cached)**      | No OPcache         |       554,015 |     1865.2 |           0.460 |

#### Bitbucket API (177 routes)

| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |
|------|------------------------------|--------------------|---------------|------------|-----------------|
|    1 |  **RadixRouter (cached)** | JIT=tracing        |     1,778,811 |      343.3 |           0.010 |
|    2 |  **RadixRouter**        | JIT=tracing        |     1,676,293 |      655.6 |           0.470 |
|    3 |  **RadixRouter (cached)** | OPcache            |     1,296,820 |      343.3 |           0.007 |
|    4 |  **RadixRouter**             | OPcache            |     1,249,282 |      655.6 |           0.384 |
|    5 |  **RadixRouter**             | No OPcache         |     1,170,249 |     1999.8 |           0.288 |
|    6 |  **RadixRouter (cached)**    | No OPcache         |     1,105,314 |     2054.3 |           0.707 |
|    7 |  **Symfony (cached)**        | JIT=tracing        |       941,383 |      461.0 |           0.065 |
|    8 |  **Symfony**                 | JIT=tracing        |       921,038 |      734.6 |           7.468 |
|    9 |  **Symfony (cached)**        | OPcache            |       741,856 |      343.6 |           0.042 |
|   10 |  **Symfony**                 | OPcache            |       722,936 |      551.5 |           9.675 |
|   11 |  **Symfony (cached)**        | No OPcache         |       671,683 |     2152.4 |           1.635 |
|   12 |  **Symfony**                 | No OPcache         |       667,433 |     2385.8 |           8.598 |
|   13 |  **FastRoute**               | JIT=tracing        |       355,373 |      596.1 |           1.203 |
|   14 |  **FastRoute (cached)**      | JIT=tracing        |       346,993 |      342.8 |           0.047 |
|   15 |  **FastRoute (cached)**      | OPcache            |       317,003 |      342.8 |           0.037 |
|   16 |  **FastRoute**               | OPcache            |       312,958 |      481.4 |           1.036 |
|   17 |  **FastRoute (cached)**      | No OPcache         |       304,748 |     1863.5 |           0.769 |
|   18 |  **FastRoute**               | No OPcache         |       303,743 |     1972.0 |           2.365 |

## Integrations

- [Mezzio](https://github.com/sirix777/mezzio-radixrouter) - RadixRouter integration for Mezzio framework

## License

This library is licensed under the **WTFPL-2.0** license. Do whatever you want with it.
