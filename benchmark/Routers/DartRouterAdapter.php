<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

require_once __DIR__ . '/../../src/DartRouter.php';

use DartRouter\Router;

use function DartRouter\add;
use function DartRouter\compile;
use function DartRouter\lookup;

class DartRouterAdapter implements RouterInterface
{
    /** @var Router|null */
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
        compile($this->router);
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
            'name' => 'DartRouter',
            'description' => 'Row-displacement-compressed radix trie router with per-method tries.',
        ];
    }
}
