<?php

use Wilaak\Http\RadixRouter;

class BenchmarkSmokeTest extends RadixRouterTestCase
{
    public function testBenchmarkRoutesRegisterAndResolve()
    {
        $benchmarks = ['avatax', 'simple', 'bitbucket', 'huge', 'aws', 'discord', 'stripe', 'kubernetes', 'github'];

        foreach ($benchmarks as $benchmark) {
            $routes = self::loadBenchmarkRoutes($benchmark);

            $r = new RadixRouter();
            foreach ($routes as $path) {
                $r->add('GET', $path, 'handler');
            }

            foreach ($routes as $path) {
                $info = $r->lookup('GET', $path);
                $this->assertSame(200, $info['code'], "Route not found: $path ($benchmark)");
                $this->assertSame('handler', $info['handler']);
            }
        }
    }

    private static function loadBenchmarkRoutes(string $benchmark): array
    {
        $routes = require __DIR__ . "/../benchmark/routes/{$benchmark}.php";

        // Benchmark fixtures use {param} syntax; convert to :param.
        foreach ($routes as &$path) {
            $path = \str_replace(['{', '}'], [':', ''], $path);
        }
        unset($path);

        return $routes;
    }
}
