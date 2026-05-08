<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

require_once __DIR__ . '/../../src/DartRouter.php';

use DartRouter\Router;

use function DartRouter\add;
use function DartRouter\compile;
use function DartRouter\lookup;

class DartRouterCachedAdapter implements RouterInterface
{
    public ?Router $router = null;
    public string $cacheFile;

    public function mount(string $tmpFile): void
    {
        $this->cacheFile = $tmpFile;
    }

    public function adapt(array $routes): array
    {
        foreach ($routes as &$route) {
            $route[1] = str_replace(['{', '}'], [':', ''], $route[1]);
        }
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $this->router = new Router();
        $router = $this->router;
        $cacheFile = $this->cacheFile;

        if (!file_exists($cacheFile)) {
            $addedPatterns = [];
            foreach ($adaptedRoutes as [$_method, $pattern]) {
                if (!in_array($pattern, $addedPatterns, true)) {
                    add($router, $pattern, 'handler');
                    $addedPatterns[] = $pattern;
                }
            }
            compile($router);

            $data = [
                $router->slots,
                $router->edges,
                $router->patterns,
                $router->handlers,
            ];

            $export = '<?php return ' . var_export($data, true) . ';';

            $tmpFile = $cacheFile . '.' . uniqid('', true) . '.tmp';
            file_put_contents($tmpFile, $export, LOCK_EX);
            rename($tmpFile, $cacheFile);
        }

        $data = require $cacheFile;
        $router->slots    = $data[0];
        $router->edges    = $data[1];
        $router->patterns = $data[2];
        $router->handlers = $data[3];
    }

    public function lookup(string $method, string $path): void
    {
        if ($this->router === null || lookup($this->router, $path) === null) {
            throw new \RuntimeException("Route not found: $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'DartRouter (cached)',
            'description' => 'Row-displacement-compressed radix trie router with per-method tries.',
        ];
    }
}
