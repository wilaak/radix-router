<?php

//
// Query Parameters
// 
$suite              = $_GET['suite'] ?? null;
$router_class       = $_GET['router'] ?? null;
$router_name        = $_GET['router_name'] ?? null;
$iterations         = isset($_GET['iterations']) ? (int) $_GET['iterations'] : null;
$benchmark_duration = isset($_GET['duration']) ? (float) $_GET['duration']   : null;
$seed               = isset($_GET['seed']) ? (int) $_GET['seed'] : 42;

//
// Parameter Validation
//

header('Content-Type: application/json; charset=utf-8');

if (!$suite || !$router_class) {
    $reason = !$suite ? 'Missing suite parameter.' : 'Missing router parameter.';
    echo json_encode([
        'error'  => 'Invalid parameters',
        'reason' => $reason,
        'usage'  => '?suite={suite_name}&router={router_class}&[iterations=N]&[duration=SECONDS]&[seed=N]',
    ]);
    exit(1);
}

if (!$router_name) {
    echo json_encode(['error' => 'Missing router_name parameter.']);
    exit(1);
}

//
// Data Preparation (not timed)
//

$route_list_file = __DIR__ . "/../cache/{$router_name}_{$suite}_{$seed}_adapted_routes.php";
if (!file_exists($route_list_file)) {
    echo json_encode(['error' => 'Adapted route list not found', 'route_list_file' => $route_list_file]);
    exit(1);
}
$routes = require $route_list_file;

$lookup_list_file = __DIR__ . "/../cache/lookup_list_{$suite}_{$seed}.php";
if (!file_exists($lookup_list_file)) {
    echo json_encode(['error' => 'Lookup list not found', 'lookup_list_file' => $lookup_list_file]);
    exit(1);
}
['methods' => $list_methods, 'paths' => $list_paths] = require $lookup_list_file;
$list_size = count($list_methods);

if (!is_dir(__DIR__ . "/../cache")) {
    mkdir(__DIR__ . "/../cache", 0777, true);
}

//
// Router Registration (timed)
//


$memory_before    = memory_get_usage();
$register_start   = hrtime(true);
require __DIR__ . '/../../vendor/autoload.php';

$router = new $router_class();
$router->mount(__DIR__ . "/../cache/{$router_name}_{$suite}_{$seed}.php");
$router->register($routes);
$router->lookup($list_methods[0], $list_paths[0]);

$register_time_ms   = (hrtime(true) - $register_start) / 1e6;
$register_memory_kb = (memory_get_usage() - $memory_before) / 1024;

//
// Warmup
//

$router->lookup($list_methods[0], $list_paths[0]);

if (function_exists('memory_reset_peak_usage')) {
    memory_reset_peak_usage();
}

//
// Benchmark
//

$total_iterations = 0;
$duration         = 0.0;

if ($iterations !== null && $iterations > 0) {
    // Fixed iteration count mode
    $list_idx = 0;
    $start    = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $router->lookup($list_methods[$list_idx], $list_paths[$list_idx]);
        if (++$list_idx === $list_size) $list_idx = 0;
    }

    $duration         = (hrtime(true) - $start) / 1e9;
    $total_iterations = $iterations;
} else {
    // Time-based mode: run in batches until the target duration is reached
    $benchmark_duration = $benchmark_duration > 0 ? $benchmark_duration : 1.0;
    $batch_size         = 50_000;
    $list_idx           = 0;
    $start              = hrtime(true);
    $elapsed_ns         = 0;

    while ($elapsed_ns / 1e9 < $benchmark_duration) {
        for ($i = 0; $i < $batch_size; $i++) {
            $router->lookup($list_methods[$list_idx], $list_paths[$list_idx]);
            if (++$list_idx === $list_size) $list_idx = 0;
        }
        $total_iterations += $batch_size;
        $elapsed_ns        = hrtime(true) - $start;
    }

    $duration = $elapsed_ns / 1e9;
}

//
// Results
//

$lookups_per_second = $duration > 0 ? $total_iterations / $duration : 0;
$peak_memory_kb     = memory_get_peak_usage() / 1024;

echo json_encode([
    'router'              => $router_name,
    'router_class'        => $router_class,
    'suite'               => $suite,
    'lookups_per_second'  => $lookups_per_second,
    'total_iterations'    => $total_iterations,
    'duration_seconds'    => $duration,
    'peak_memory_kb'      => $peak_memory_kb,
    'register_memory_kb'  => $register_memory_kb,
    'register_time_ms'    => $register_time_ms,
]);
exit(0);
