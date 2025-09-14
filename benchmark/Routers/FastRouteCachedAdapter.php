<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;
use function FastRoute\cachedDispatcher;

class FastRouteCachedAdapter implements RouterInterface
{
    public Dispatcher $dispatcher;
    public string $cacheFile;

    public function mount(string $tmpFile): void
    {
        $this->cacheFile = $tmpFile;
    }

    public function adapt(array $routes): array
    {
        // FastRoute uses curly braces for parameters, so no adaptation needed
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $dispatcher = cachedDispatcher(function (RouteCollector $r) use ($adaptedRoutes) {
            foreach ($adaptedRoutes as $pattern) {
                $r->addRoute('GET', $pattern, 'handler');
            }
        }, [
            'cacheFile' => $this->cacheFile,
            'cacheDisabled' => false,
        ]);

        $this->dispatcher = $dispatcher;
    }

    public function lookup(string $path): void
    {
        $info = $this->dispatcher->dispatch('GET', $path);
        if ($info[0] !== Dispatcher::FOUND) {
            throw new \RuntimeException("Route not found: $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'FastRoute (cached)',
            'description' => 'Fast regular expression based routing library for PHP.',
        ];
    }
}
