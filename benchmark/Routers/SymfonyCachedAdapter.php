<?php

namespace Wilaak\Http\RadixRouter\Benchmark\Routers;

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class SymfonyCachedAdapter implements RouterInterface
{
    public CompiledUrlMatcher $matcher;
    private string $tmpFile = '';

    public function mount(string $tmpFile): void
    {
        $this->tmpFile = $tmpFile;
    }

    public function adapt(array $routes): array
    {
        // Symfony uses {param} syntax, so no adaptation needed
        return $routes;
    }

    public function register(array $adaptedRoutes): void
    {
        $routeCollection = new RouteCollection();
        if (file_exists($this->tmpFile)) {
            $compiledRoutes = require $this->tmpFile;
            $context = new RequestContext('/');
            $this->matcher = new CompiledUrlMatcher($compiledRoutes, $context);
            return;
        }

        foreach ($adaptedRoutes as $path) {
            $routeCollection->add($path, new Route($path, ['_controller' => 'handler']));
        }
        $context = new RequestContext('/');
        $dumper = new CompiledUrlMatcherDumper($routeCollection);
        $compiledRoutes = $dumper->getCompiledRoutes();

        // Cache compiled routes to file
        file_put_contents(
            $this->tmpFile,
            '<?php return ' . var_export($compiledRoutes, true) . ';'
        );

        $this->matcher = new CompiledUrlMatcher($compiledRoutes, $context);
    }

    public function lookup(string $path): void
    {
        try {
            $this->matcher->match($path);
        } catch (ResourceNotFoundException $e) {
            throw new \RuntimeException("Route not found: $path");
        }
    }

    public static function details(): array
    {
        return [
            'name' => 'Symfony (cached)',
            'description' => 'Symfony Routing component adapter for benchmarking.',
        ];
    }
}