<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use Wilaak\Http\RadixRouter as WilaakRadixRouter;

class RadixRouterCachedAdapter implements RouterInterface
{
    public WilaakRadixRouter $router;
    public string $cacheFile;

    public function mount(string $tmpFile): void
    {
        $this->cacheFile = $tmpFile;
    }

    public function adapt(array $routes): array
    {
        // Convert curly braces to colon syntax for RadixRouter compatibility
        foreach ($routes as &$path) {
            $path = str_replace('{', ':', $path);
            $path = str_replace('}', '', $path);
        }
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $this->router = new WilaakRadixRouter();
        $router = &$this->router;
        $cacheFile = $this->cacheFile;

        if (!file_exists($cacheFile)) {
            // Build and register your routes here
            foreach ($adaptedRoutes as $pattern) {
                $this->router->add('GET', $pattern, 'handler');
            }
            // Prepare the data to cache
            $routes = [
                $router->tree,
                $router->static,
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
        $router->tree = $routes[0];
        $router->static = $routes[1];
    }

    public function lookup(string $path): void
    {
        $info = $this->router->lookup('GET', $path);
        if ($info['code'] !== 200) {
            throw new \RuntimeException("Route not found: $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'RadixRouter (cached)',
            'description' => 'A high-performance PHP router using a radix tree for dynamic route matching.',
        ];
    }
}