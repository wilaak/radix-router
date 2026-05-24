<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

require_once __DIR__ . '/../../src/DartRouterSimple.php';

use DartRouterSimple\Router;

use function DartRouterSimple\add;
use function DartRouterSimple\lookup;

class DartRouterSimpleAdapter implements RouterInterface
{
    public ?Router $router = null;

    public function mount(string $tmpFile): void
    {
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
        $addedPatterns = [];
        foreach ($adaptedRoutes as [$_method, $pattern]) {
            if (!in_array($pattern, $addedPatterns, true)) {
                add($this->router, $pattern, 'handler');
                $addedPatterns[] = $pattern;
            }
        }
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
            'name' => 'DartRouter (simple)',
            'description' => 'Plain radix trie with per-node hashmaps; no row-displacement packing.',
        ];
    }
}
