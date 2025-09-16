<?php

require __DIR__ . '/../vendor/autoload.php';

use Wilaak\Http\RadixRouter\Benchmark\Routers\{
    RadixRouterAdapter,
    RadixRouterCachedAdapter,
    FastRouteAdapter,
    FastRouteCachedAdapter,
    SymfonyAdapter,
    SymfonyCachedAdapter,
};

function startWebServer($port, $phpOpts = '')
{
    $docRoot = escapeshellarg(__DIR__ . '/public');
    $cmd = sprintf(
        'php -n %s -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!',
        $phpOpts,
        $port,
        $docRoot
    );
    exec($cmd, $output);
    $pid = (int) ($output[0] ?? 0);
    if ($pid) {
        register_shutdown_function(fn() => exec('kill ' . $pid . ' > /dev/null 2>&1'));
    }

    // Wait for server to be up
    $url = "http://127.0.0.1:$port/";
    for ($i = 0; $i < 20; $i++) {
        if (@file_get_contents($url) !== false) {
            return $pid;
        }
        usleep(100000);
    }
    if ($pid) {
        exec('kill ' . $pid . ' > /dev/null 2>&1');
    }
    throw new RuntimeException("Failed to start PHP server on 127.0.0.1:$port");
}

function getUnusedPort(): int
{
    $sock = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) explode(':', $name)[1];
}

function runBenchmark($port, $suite, $routerClass, $iterations = 1000000, $duration = 1)
{
    $params = [
        'suite' => $suite,
        'router' => $routerClass,
        'iterations' => $iterations,
        'duration' => $duration,
    ];
    $url = "http://127.0.0.1:$port/?" . http_build_query(array_filter($params, fn($v) => $v !== null));
    $json = file_get_contents($url);
    $data = json_decode($json, true);

    if ($json === false || !is_array($data)) {
        throw new RuntimeException("Failed to get benchmark result from $url : $json");
    }
    if (isset($data['error'])) {
        throw new RuntimeException("Benchmark error: " . $json);
    }
    return $data;
}

function getTestSuiteRoutesLength($suite): int
{
    $file = __DIR__ . '/routes/' . $suite . '.php';
    if (!file_exists($file)) {
        throw new InvalidArgumentException("Test suite file not found: $file");
    }
    $routes = require $file;
    return count($routes);
}

function startServers(array $benchmarkModes): array
{
    $servers = [];
    foreach ($benchmarkModes as [$modeLabel, $phpOpts]) {
        $port = getUnusedPort();
        $servers[$modeLabel] = [
            'port' => $port,
            'pid' => startWebServer($port, $phpOpts),
        ];
        echo "Started PHP server for mode: $modeLabel on port $port\n";
    }
    return $servers;
}

function warmupServers(array $allTestSuites, array $allRouters, array $benchmarkModes, array $servers): void
{
    $totalWarmups = count($allTestSuites) * count($allRouters) * count($benchmarkModes);
    $warmupCount = 0;
    echo "Warming up ($totalWarmups combinations)...\n";
    foreach ($allTestSuites as $suite) {
        foreach ($allRouters as $routerClass) {
            foreach ($benchmarkModes as [$modeLabel, $phpOpts]) {
                $port = $servers[$modeLabel]['port'];
                runBenchmark($port, $suite, $routerClass, 1, 0);
                $warmupCount++;
                if ($warmupCount % 5 === 0 || $warmupCount === $totalWarmups) {
                    echo "  Warmed up $warmupCount / $totalWarmups\n";
                }
            }
        }
    }
}

function runBenchmarks(
    array $allTestSuites,
    array $allRouters,
    array $benchmarkModes,
    array $servers,
    ?int $iterations = null,
    float $duration = 4
): array {
    $results = [];
    $totalBenchmarks = count($allTestSuites) * count($allRouters) * count($benchmarkModes);
    $benchCount = 0;

    echo "Running benchmarks ($totalBenchmarks combinations)...\n";
    foreach ($allTestSuites as $suite) {
        foreach ($allRouters as $routerClass) {
            $routerName = method_exists($routerClass, 'details') ? $routerClass::details()['name'] : $routerClass;
            foreach ($benchmarkModes as [$modeLabel, $phpOpts]) {
                $port = $servers[$modeLabel]['port'];
                $result = runBenchmark($port, $suite, $routerClass, $iterations, $duration);
                $row = [
                    'suite' => $suite,
                    'router' => $routerName,
                    'mode' => $modeLabel,
                    'lookups_per_second' => $result['lookups_per_second'] ?? 0,
                    'peak_memory_kb' => $result['peak_memory_kb'] ?? 0,
                    'register_time_ms' => $result['register_time_ms'] ?? null,
                    'router_class' => $routerClass,
                ];
                $results[] = $row;
                $benchCount++;
                if ($benchCount % 5 === 0 || $benchCount === $totalBenchmarks) {
                    echo "  Benchmarked $benchCount / $totalBenchmarks\n";
                }
            }
        }
    }
    return $results;
}

function benchmarkRegistrationTimes(
    array &$results,
    array $servers,
    int $samples = 10,
    float $duration = 0.1
): void {
    echo "\nBenchmarking registration times (averaged over $samples samples)...\n";
    $totalReg = count($results);
    $regCount = 0;
    foreach ($results as &$row) {
        $port = $servers[$row['mode']]['port'];
        $registerTimes = [];
        $routerClass = $row['router_class'];
        for ($i = 0; $i < $samples; $i++) {
            $result = runBenchmark($port, $row['suite'], $routerClass, 1, $duration);
            if (isset($result['register_time_ms'])) {
                $registerTimes[] = $result['register_time_ms'];
            }
        }
        $avgRegister = $registerTimes ? array_sum($registerTimes) / count($registerTimes) : null;
        $row['register_time_ms'] = $avgRegister;
        $regCount++;
        if ($regCount % 5 === 0 || $regCount === $totalReg) {
            echo "  Registration time $regCount / $totalReg\n";
        }
    }
    unset($row);
}



function printCombinedBenchmarkTable(array $results): void
{
    // Group results by suite
    $bySuite = [];
    foreach ($results as $row) {
        $bySuite[$row['suite']][] = $row;
    }
    $trophies = ["🏆", "🥈", "🥉"]; // 🏆, 🥈, 🥉
    foreach ($bySuite as $suite => $suiteRows) {
        $routesCount = getTestSuiteRoutesLength($suite);
        echo "\n#### $suite ($routesCount routes)\n";
        echo "| Rank | Router                       | Mode               | Lookups/sec   | Mem (KB)   | Register (ms)   |\n";
        echo "|------|------------------------------|--------------------|---------------|------------|-----------------|\n";
        // Sort by lookups/sec descending
        usort($suiteRows, fn($a, $b) => $b['lookups_per_second'] <=> $a['lookups_per_second']);
        foreach ($suiteRows as $i => $row) {
            $trophy = $i < 3 ? ' ' . $trophies[$i] : '';
            $routerBold = $trophy . ' ' . '**' . $row['router'] . '**';
            printf(
                "| %4s | %-28s | %-18s | %13s | %10.1f | %15.3f |\n",
                $i + 1,
                $routerBold,
                $row['mode'],
                number_format($row['lookups_per_second']),
                $row['peak_memory_kb'],
                $row['register_time_ms'] ?? 0
            );
        }
    }
}

function printUsageScreen($cliSuites, $cliRouters, $cliModes) {
    echo "\nUsage: " . __FILE__ . " [--suite=\"simple, avatax, bitbucket\"] [--router=\"RadixRouterAdapter, FastRouteAdapter,...\"] [--mode=\"JIT=tracing, OPcache, No OPcache\"] [--all]\n";
    echo "\nOptions:\n";
    echo "  --suite   Comma-separated list of test suites (default: all)\n";
    echo "  --router  Comma-separated list of routers (default: all)\n";
    echo "  --mode    Comma-separated list of benchmark modes (default: all)\n";
    echo "  --all     Run all suites, routers, and modes\n";
    echo "\nAvailable suites: " . implode(', ', $cliSuites) . "\n";
    echo "Available routers: " . implode(', ', array_keys($cliRouters)) . "\n";
    echo "Available modes: " . implode(', ', array_keys($cliModes)) . "\n";
}


$cliSuites = ['simple', 'avatax', 'bitbucket', 'huge'];
$cliRouters = [
    'RadixRouterAdapter' => RadixRouterAdapter::class,
    'RadixRouterCachedAdapter' => RadixRouterCachedAdapter::class,
    'FastRouteAdapter' => FastRouteAdapter::class,
    'FastRouteCachedAdapter' => FastRouteCachedAdapter::class,
    'SymfonyAdapter' => SymfonyAdapter::class,
    'SymfonyCachedAdapter' => SymfonyCachedAdapter::class,
];
$cliModes = [
    'JIT=tracing' => ['JIT=tracing', '-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M -d opcache.jit=tracing'],
    'OPcache' => ['OPcache', '-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1'],
    'No OPcache' => ['No OPcache', '-d opcache.enable=0'],
];

$opts = getopt('', ['suite::', 'router::', 'mode::', 'all']);

if (isset($opts['all'])) {
    $allTestSuites = $cliSuites;
    $allRouters = array_values($cliRouters);
    $benchmarkModes = array_values($cliModes);
} else {
    $hasAnyOption = isset($opts['suite']) || isset($opts['router']) || isset($opts['mode']);
    if (!$hasAnyOption) {
        printUsageScreen($cliSuites, $cliRouters, $cliModes);
        exit(1);
    }

    // Suite selection
    $allTestSuites = [];
    if (isset($opts['suite'])) {
        foreach (str_getcsv($opts['suite']) as $suite) {
            $suite = trim($suite);
            if (in_array($suite, $cliSuites, true)) {
                $allTestSuites[] = $suite;
            }
        }
    }
    if (!$allTestSuites) {
        $allTestSuites = $cliSuites;
    }

    // Router selection
    $allRouters = [];
    if (isset($opts['router'])) {
        foreach (str_getcsv($opts['router']) as $router) {
            $router = trim($router);
            if (isset($cliRouters[$router])) {
                $allRouters[] = $cliRouters[$router];
            }
        }
    }
    if (!$allRouters) {
        $allRouters = array_values($cliRouters);
    }

    // Mode selection
    $benchmarkModes = [];
    if (isset($opts['mode'])) {
        foreach (str_getcsv($opts['mode']) as $mode) {
            $mode = trim($mode);
            if (isset($cliModes[$mode])) {
                $benchmarkModes[] = $cliModes[$mode];
            }
        }
    }
    if (!$benchmarkModes) {
        $benchmarkModes = array_values($cliModes);
    }
}

if (!$allTestSuites || !$allRouters) {
    printUsageScreen($cliSuites, $cliRouters, $cliModes);
    exit(1);
}
if (!$benchmarkModes) {
    $benchmarkModes = array_values($cliModes);
}

echo "If you encounter any issues, try deleting the cache folder.\n";
echo "\n";
echo "Selected suites: " . implode(', ', $allTestSuites) . "\n";
echo "Selected routers: " . implode(', ', array_map(function ($r) use ($cliRouters) {
    return array_search($r, $cliRouters, true);
}, $allRouters)) . "\n";
echo "Selected modes: " . implode(', ', array_map(function ($m) {
    return $m[0]; }, $benchmarkModes)) . "\n\n";

$servers = startServers($benchmarkModes);
warmupServers($allTestSuites, $allRouters, $benchmarkModes, $servers);

$results = runBenchmarks($allTestSuites, $allRouters, $benchmarkModes, $servers);
benchmarkRegistrationTimes($results, $servers, 100, 0.1);
printCombinedBenchmarkTable($results);

echo "\nTo ensure best results, run this test multiple times.\n";