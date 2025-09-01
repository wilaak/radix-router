# RadixRouter

High-performance HTTP request router for PHP (see [benchmarks](#benchmarks) and [integrations](#integrations))

## Install

Install with composer:

    composer require wilaak/radix-router

Requires PHP 8.0 or newer

## Usage Example

Here's a basic usage example using the SAPI environment:

```php
// Create a new RadixRouter instance
$router = new \Wilaak\Http\RadixRouter();

// Register a route with an optional parameter and a handler
$router->add('GET', '/:world?', function ($world = 'World') {
    echo "Hello, $world!";
});

// Get the HTTP method (GET, POST, etc.)
$method = $_SERVER['REQUEST_METHOD'];

// Get the request path, ignoring query string
$path = rawurldecode(
    strtok($_SERVER['REQUEST_URI'], '?')
);

// Look up the route for the current method and path
$result = $router->lookup($method, $path);

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

## Configuring Routes

You can assign any value as the handler. The order of route matching is:  static > parameter > wildcard. Below is an example showing the different ways to define routes:

```php
// Static route
$router->add('GET', '/', 'handler');

// Multiple methods
$router->add(['GET', 'POST'], '/form', 'handler');

// Required parameters
$router->add('GET', '/users/:id', 'handler');
// Example requests:
//   /users/123 -> matches (captures ["id" => "123"])
//   /users     -> no match

// Optional parameters
$router->add('GET', '/archive/:year?/:month?', 'handler');
// Example requests:
//   /archive         -> matches
//   /archive/1974    -> matches (captures ["year" => "1974"])
//   /archive/1974/06 -> matches (captures ["year" => "1974", "month" => "06"])

// Wildcard parameter
$router->add('GET', '/files/:path*', 'handler');
// Example requests:
//   /files                  -> matches (captures ["path" => ""])
//   /files/readme.txt       -> matches (captures ["path" => "readme.txt"])
//   /files/images/photo.jpg -> matches (captures ["path" => "images/photo.jpg"])
```

## Route Caching

Rebuilding the routes on every request can slow down performance. Here is a simple cache implementation:

```php
$cacheFile = __DIR__ . '/routes.cache.php';

if (!file_exists($cacheFile)) {
    // Build and register your routes here
    $router->add('GET', '/', 'handler');
    // ...add more routes as needed

    // Prepare the data to cache
    $routes = [
        'tree'   => $router->tree,
        'static' => $router->static,
    ];

    // Export as PHP code for fast loading
    $export = '<?php return ' . var_export($routes, true) . ';';

    // Atomically write cache file
    $tmpFile = $cacheFile . '.' . uniqid('', true) . '.tmp';
    file_put_contents($tmpFile, $export, LOCK_EX);
    rename($tmpFile, $cacheFile);
}

// Load cached routes
$routes = require $cacheFile;
$router->tree   = $routes['tree'];
$router->static = $routes['static'];
```

By storing your routes in a PHP file, you let PHP’s OPcache handle the heavy lifting, making startup times nearly instantaneous.

## Benchmarks


Single-threaded benchmark (Intel Xeon E-2136, PHP 8.4.8 cli OPcache enabled):

#### Simple App (33 Routes)

| Router           | Register     | Lookups           | Memory Usage | Peak Memory  |
|------------------|--------------|-------------------|--------------|--------------|
| **RadixRouter**  | 0.05 ms      | 2,977,816/sec     | 384 KB       | 458 KB       |
| **FastRoute**    | 1.92 ms      | 2,767,883/sec     | 429 KB       | 1,337 KB     |
| **SymfonyRouter**| 6.84 ms      | 1,722,432/sec     | 573 KB       | 1,338 KB     |

#### Avatax API (256 Routes)

| Router           | Register     | Lookups           | Memory Usage | Peak Memory  |
|------------------|--------------|-------------------|--------------|--------------|
| **RadixRouter**  | 0.27 ms      | 2,006,929/sec     | 688 KB       | 690 KB       |
| **FastRoute**    | 4.94 ms      |   707,516/sec     | 549 KB       | 1,337 KB     |
| **SymfonyRouter**| 12.60 ms     | 1,182,060/sec     | 1,291 KB     | 1,587 KB     |

#### Bitbucket API (178 Routes)

| Router           | Register     | Lookups           | Memory Usage | Peak Memory  |
|------------------|--------------|-------------------|--------------|--------------|
| **RadixRouter**  | 0.23 ms      | 1,623,718/sec     | 641 KB       | 643 KB       |
| **FastRoute**    | 3.81 ms      |   371,104/sec     | 555 KB       | 1,337 KB     |
| **SymfonyRouter**| 12.16 ms     |   910,064/sec     | 1,180 KB     | 1,419 KB     |



## Handling HEAD Requests 

If you are running outside a SAPI environment (e.g., in a custom server), ensure your GET routes also respond correctly to HEAD requests. Responses to HEAD requests must not include a message body.

## Integrations

- [Mezzio](https://github.com/sirix777/mezzio-radixrouter) - RadixRouter integration for Mezzio framework

## License

This library is licensed under the **WTFPL-2.0** license. Do whatever you want with it.
