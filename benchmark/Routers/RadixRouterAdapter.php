<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use Wilaak\Http\RadixRouter as WilaakRadixRouter;

class RadixRouterAdapter implements RouterInterface
{
    public WilaakRadixRouter $router;

    public function mount(string $tmpFile): void
    {
    }

    public function adapt(array $routes): array
    {
        // Convert curly braces to colon syntax for RadixRouter compatibility
        foreach ($routes as &$route) {
            $route[1] = str_replace('{', ':', $route[1]);
            $route[1] = str_replace('}', '', $route[1]);
        }
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $this->router = new WilaakRadixRouter();
        foreach ($adaptedRoutes as [$method, $pattern]) {
            $this->router->add($method, $pattern, 'handler');
        }
    }

    public function lookup(string $method, string $path): void
    {
        $info = $this->router->lookup($method, $path);
        if ($info['code'] !== 200) {
            throw new \RuntimeException("Route not found: $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'RadixRouter',
            'description' => 'A high-performance PHP router using a radix tree for dynamic route matching.',
        ];
    }
}