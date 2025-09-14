<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;
use function FastRoute\simpleDispatcher;

class FastRouteAdapter implements RouterInterface
{
    public Dispatcher $dispatcher;

    public function mount(string $tmpFile): void
    {
        // ...
    }

    public function adapt(array $routes): array
    {
        // FastRoute uses curly braces for parameters, so no adaptation needed
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r) use ($adaptedRoutes) {
            foreach ($adaptedRoutes as $pattern) {
                $r->addRoute('GET', $pattern, 'handler');
            }
        });

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
            'name' => 'FastRoute',
            'description' => 'Fast regular expression based routing library for PHP.',
        ];
    }
}
