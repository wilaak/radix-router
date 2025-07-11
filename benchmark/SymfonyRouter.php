<?php
require 'vendor/autoload.php';

use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;

$routes = require __DIR__ . "/routes/" . ($argv[1] ?? 'avatax') . ".php";

$registrationStart = microtime(true);
$routeCollection = new RouteCollection();
foreach ($routes as $path) {
    $routeCollection->add($path, new Route($path, ['_controller' => 'handler']));
}
$context = new RequestContext('/');

$dumper = new CompiledUrlMatcherDumper($routeCollection);
$compiledRoutes = $dumper->getCompiledRoutes();
$matcher = new CompiledUrlMatcher($compiledRoutes, $context);

$registrationEnd = microtime(true);
$registrationDuration = $registrationEnd - $registrationStart;

$iterations = 2000000;
$start = microtime(true);
$routeCount = count($routes);

for ($i = 0; $i < $iterations; $i++) {
    $index = $i % $routeCount;
    $path = $routes[$index];
    try {
        $matcher->match($path);
    } catch (\Symfony\Component\Routing\Exception\ResourceNotFoundException $e) {
        die("Route not found: $path\n");
    }
}

$end = microtime(true);
$duration = $end - $start;
$lookupsPerSecond = $iterations / $duration;

echo "Registration time: " . number_format($registrationDuration * 1000, 3) . " ms" . PHP_EOL;
echo "Lookups per second: " . number_format($lookupsPerSecond, 2) . PHP_EOL;
echo "Memory: " . number_format(memory_get_usage() / 1024, 2) . " KB" . PHP_EOL;
echo "Peak memory: " . number_format(memory_get_peak_usage() / 1024, 2) . " KB" . PHP_EOL;
echo "Registered routes: " . count($routes) . PHP_EOL;