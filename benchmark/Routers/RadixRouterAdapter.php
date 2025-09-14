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
        foreach ($routes as &$path) {
            $path = str_replace('{', ':', $path);
            $path = str_replace('}', '', $path);
        }
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $this->router = new WilaakRadixRouter();
        foreach ($adaptedRoutes as $pattern) {
            $this->router->add('GET', $pattern, 'handler');
        }
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
            'name' => 'RadixRouter',
            'description' => 'A high-performance PHP router using a radix tree for dynamic route matching.',
        ];
    }
}