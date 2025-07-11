<?php
require 'RadixRouter/src/RadixRouter.php';

$routes = require __DIR__ . "/routes/" . ($argv[1] ?? 'avatax') . ".php";

// Convert curly braces to colon syntax for RadixRouter compatibility
foreach ($routes as &$path) {
    $path = str_replace('{', ':', $path);
    $path = str_replace('}', '', $path);
}
unset($path);

use Wilaak\Http\RadixRouter;

$r = new RadixRouter();

$registrationStart = microtime(true);
foreach ($routes as $path) {
    $r->add('GET', $path, 'handler');
}
$registrationEnd = microtime(true);
$registrationDuration = $registrationEnd - $registrationStart;

$iterations = 2000000;
$start = microtime(true);
$routeCount = count($routes);

for ($i = 0; $i < $iterations; $i++) {
    $index = $i % $routeCount;
    $path = $routes[$index];
    $info = $r->lookup('GET', $path);
    if ($info['code'] !== 200) {
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
