<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

class SymfonyAdapter implements RouterInterface
{
    public CompiledUrlMatcher $matcher;

    public function mount(string $tmpFile): void
    {
    }

    public function adapt(array $routes): array
    {
        // Symfony uses {param} syntax, so no adaptation needed
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $routeCollection = new RouteCollection();
        $i = 0;
        foreach ($adaptedRoutes as [$method, $path]) {
            $routeCollection->add($i++, new Route($path, ['_controller' => 'handler'], [], [], '', [], [$method]));
        }
        $context = new RequestContext('/');
        $dumper = new CompiledUrlMatcherDumper($routeCollection);
        $compiledRoutes = $dumper->getCompiledRoutes();
        $this->matcher = new CompiledUrlMatcher($compiledRoutes, $context);
    }

    public function lookup(string $method, string $path): void
    {
        try {
            $this->matcher->getContext()->setMethod($method);
            $this->matcher->match($path);
        } catch (ResourceNotFoundException $e) {
            throw new \RuntimeException("Route not found: $path");
        } catch (MethodNotAllowedException $e) {
            throw new \RuntimeException("Method not allowed: $method $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'Symfony',
            'description' => 'Symfony Routing component adapter for benchmarking.',
        ];
    }
}