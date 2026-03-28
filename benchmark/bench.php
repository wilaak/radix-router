<?php

require __DIR__ . '/../vendor/autoload.php';

use Wilaak\Http\RadixRouter\Benchmark\Routers\RouterInterface;

function format_eta(float $seconds): string
{
    if ($seconds < 1) return '<1s';
    if ($seconds < 60) return sprintf('%ds', (int) $seconds);
    return sprintf('%dm %02ds', (int) ($seconds / 60), (int) $seconds % 60);
}

function normalize_router_name(string $name): string
{
    return preg_replace('/[ (]+/', '-', strtolower(rtrim($name, ')')));
}

function get_route_count(string $suite): int
{
    return count(require __DIR__ . "/routes/{$suite}.php");
}

//
// Progress display
//

function print_progress(int $done, int $total, string $label, string $result, ?float $eta): void
{
    $width = strlen((string) $total);
    $number = str_pad((string) $done, $width, ' ', STR_PAD_LEFT);
    $eta_str = ($eta !== null && $eta > 0) ? '  ETA ' . format_eta($eta) : '';
    echo "  [$number/$total]  $label  $result$eta_str\n";
}

//
// Server management
//

function get_unused_port(): int
{
    $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    return (int) explode(':', $name)[1];
}

function start_web_server(int $port, string $php_options = ''): int
{
    $document_root = escapeshellarg(__DIR__ . '/www');
    $command = sprintf(
        'php -n %s -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!',
        $php_options, $port, $document_root
    );
    exec($command, $output);
    $pid = (int) ($output[0] ?? 0);
    if ($pid) {
        register_shutdown_function(fn() => exec("kill $pid > /dev/null 2>&1"));
    }
    for ($i = 0; $i < 20; $i++) {
        if (@file_get_contents("http://127.0.0.1:$port/") !== false) return $pid;
        usleep(100_000);
    }
    if ($pid) exec("kill $pid > /dev/null 2>&1");
    throw new RuntimeException("Failed to start PHP server on port $port");
}

//
// Benchmark HTTP call
//

function fetch_benchmark(int $port, string $suite, string $router_class, string $router_name, ?int $iterations, float $duration, int $seed): array
{
    $params = array_filter([
        'suite'       => $suite,
        'router'      => $router_class,
        'router_name' => $router_name,
        'iterations'  => $iterations,
        'duration'    => $duration,
        'seed'        => $seed,
    ], fn($value) => $value !== null);
    $url = "http://127.0.0.1:$port/?" . http_build_query($params);
    $json = file_get_contents($url);
    $data = json_decode($json, true);
    if (!is_array($data)) throw new RuntimeException("Benchmark failed at $url: $json");
    if (isset($data['error'])) throw new RuntimeException("Benchmark error: $json");
    return $data;
}

//
// Route generation
//

function generate_routes_for_router(string $router_name, string $suite, int $seed): void
{
    $cache_dir = __DIR__ . '/cache';
    $output_file = "$cache_dir/{$router_name}_{$suite}_{$seed}_routes.php";
    if (file_exists($output_file)) return;
    if (!is_dir($cache_dir)) mkdir($cache_dir, 0755, true);

    $paths = require __DIR__ . "/routes/{$suite}.php";
    mt_srand($seed);

    $weighted_methods = [...array_fill(0, 12, 'GET'), ...array_fill(0, 5, 'POST'), ...array_fill(0, 2, 'PUT'), 'DELETE'];
    $method_count = count($weighted_methods);

    $routes = [];
    foreach ($paths as $path) {
        $num_methods = mt_rand(1, 3);
        $selected = [];
        while (count($selected) < $num_methods) {
            $method = $weighted_methods[mt_rand(0, $method_count - 1)];
            if (!in_array($method, $selected, true)) $selected[] = $method;
        }
        foreach ($selected as $method) {
            $routes[] = [$method, $path];
        }
    }

    file_put_contents($output_file, '<?php return ' . var_export($routes, true) . ";\n");
}

function generate_lookup_list(string $suite, int $seed, string $sample_router_name): void
{
    $cache_dir = __DIR__ . '/cache';
    $output_file = "$cache_dir/lookup_list_{$suite}_{$seed}.php";
    if (file_exists($output_file)) return;

    $routes = require "$cache_dir/{$sample_router_name}_{$suite}_{$seed}_routes.php";

    $path_methods = [];
    foreach ($routes as [$method, $path]) {
        $path_methods[$path][] = $method;
    }
    $paths = array_keys($path_methods);
    $path_count = count($paths);

    mt_srand($seed + 1);

    $ranks = range(1, $path_count);
    mt_shuffle($ranks);
    $weights = array_map(fn($i) => 1.0 / pow($ranks[$i], 0.9), array_keys($paths));
    $total_weight = array_sum($weights);
    $frequency_table = array_merge(...array_map(
        fn($i, $w) => array_fill(0, max(1, (int) round($w / $total_weight * 10000)), $i),
        array_keys($weights), $weights
    ));
    $table_size = count($frequency_table);

    $method_weights = ['GET' => 0.75, 'POST' => 0.15, 'PUT' => 0.07, 'DELETE' => 0.03];
    $list_size = max(2000, $path_count * 5);
    $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $char_count = strlen($chars);

    $list_methods = [];
    $list_paths = [];

    for ($n = 0; $n < $list_size; $n++) {
        $path_index = $frequency_table[mt_rand(0, $table_size - 1)];
        $path = $paths[$path_index];
        $list_methods[] = select_method_weighted($path_methods[$path], $method_weights);
        $list_paths[] = preg_replace_callback('/\{[^}]+\}/', function () use ($chars, $char_count) {
            if (mt_rand(0, 1) === 0) return (string) mt_rand(1, 99999999);
            $length = mt_rand(3, 36);
            $slug = '';
            for ($i = 0; $i < $length; $i++) $slug .= $chars[mt_rand(0, $char_count - 1)];
            return $slug;
        }, $path);
    }

    $order = range(0, $list_size - 1);
    mt_shuffle($order);
    $list_methods = array_map(fn($i) => $list_methods[$i], $order);
    $list_paths   = array_map(fn($i) => $list_paths[$i], $order);

    file_put_contents($output_file, '<?php return ' . var_export(['methods' => $list_methods, 'paths' => $list_paths], true) . ";\n");
}

function select_method_weighted(array $registered, array $weights): string
{
    $available = array_intersect_key($weights, array_flip($registered));
    if (empty($available)) return $registered[mt_rand(0, count($registered) - 1)];
    $total = array_sum($available);
    $random = (mt_rand() / mt_getrandmax()) * $total;
    $cumulative = 0.0;
    foreach ($available as $method => $weight) {
        $cumulative += $weight;
        if ($random <= $cumulative) return $method;
    }
    return array_key_last($available);
}

function mt_shuffle(array &$array): void
{
    for ($i = count($array) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        if ($i !== $j) [$array[$i], $array[$j]] = [$array[$j], $array[$i]];
    }
}

function adapt_router_routes(string $router_class, string $router_name, string $suite, int $seed): void
{
    $cache_dir = __DIR__ . '/cache';
    $routes_file = "$cache_dir/{$router_name}_{$suite}_{$seed}_routes.php";
    $adapted_file = "$cache_dir/{$router_name}_{$suite}_{$seed}_adapted_routes.php";
    if (file_exists($adapted_file)) return;
    $routes = require $routes_file;
    $router = new $router_class();
    file_put_contents($adapted_file, '<?php return ' . var_export($router->adapt($routes), true) . ";\n");
}

//
// Benchmark execution
//

function run_benchmarks(string $heading, array $suites, array $routers, array $modes, array $servers, ?int $iterations, float $duration, int $seed, bool $store_results): array
{
    $results = [];
    $total = count($suites) * count($routers) * count($modes);
    $done = 0;
    $start = hrtime(true);
    echo "\n$heading ($total combinations, {$duration}s each)\n";

    foreach ($suites as $suite) {
        foreach ($routers as $router_class) {
            $router_real_name = $router_class::details()['name'];
            $router_name = normalize_router_name($router_real_name);
            foreach ($modes as [$mode_label]) {
                $label = sprintf('%-22s  %-10s  %-12s', $router_name, $suite, $mode_label);
                $result = fetch_benchmark($servers[$mode_label]['port'], $suite, $router_class, $router_name, $iterations, $duration, $seed);
                $done++;
                $elapsed = (hrtime(true) - $start) / 1e9;
                $eta = $done < $total ? ($elapsed / $done) * ($total - $done) : null;
                print_progress($done, $total, $label, number_format($result['lookups_per_second'] ?? 0) . '/s', $eta);
                if ($store_results) {
                    $results[] = [
                        'suite'               => $suite,
                        'router'              => $router_real_name,
                        'router_name'         => $router_name,
                        'mode'                => $mode_label,
                        'lookups_per_second'  => $result['lookups_per_second'] ?? 0,
                        'peak_memory_kb'      => $result['peak_memory_kb'] ?? 0,
                        'register_memory_kb'  => $result['register_memory_kb'] ?? 0,
                        'register_time_ms'    => null,
                        'router_class'        => $router_class,
                    ];
                }
            }
        }
    }
    return $results;
}

function benchmark_registration(array &$results, array $servers, int $samples, float $duration, int $seed): void
{
    $total = count($results);
    $done = 0;
    $start = hrtime(true);
    echo "\nBenchmarking registration times (averaged over $samples samples)\n";

    foreach ($results as &$row) {
        $label = sprintf('%-22s  %-10s  %-12s', $row['router_name'], $row['suite'], $row['mode']);
        $times = [];
        for ($i = 0; $i < $samples; $i++) {
            $result = fetch_benchmark($servers[$row['mode']]['port'], $row['suite'], $row['router_class'], $row['router_name'], 1, $duration, $seed);
            if (isset($result['register_time_ms'])) $times[] = $result['register_time_ms'];
        }
        $row['register_time_ms'] = $times ? array_sum($times) / count($times) : null;
        $done++;
        $elapsed = (hrtime(true) - $start) / 1e9;
        $eta = $done < $total ? ($elapsed / $done) * ($total - $done) : null;
        print_progress($done, $total, $label, number_format($row['register_time_ms'] ?? 0, 3) . ' ms', $eta);
    }
    unset($row);
}

//
// Results display and saving
//

function print_results_table(array $results): void
{
    $by_suite = [];
    foreach ($results as $row) $by_suite[$row['suite']][] = $row;

    $format    = '| %2s | %-22s | %-12s | %13s | %13s | %13s | %12s | %9s |';
    $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), [2, 22, 12, 13, 13, 13, 12, 9])) . '+';
    $header    = sprintf($format, '#', 'Router', 'Mode', 'RPS', 'Cold RPS', 'Peak (KB)', 'Reg (KB)', 'Boot (ms)');

    foreach ($by_suite as $suite => $rows) {
        usort($rows, fn($a, $b) => $b['lookups_per_second'] <=> $a['lookups_per_second']);
        echo "\n$suite — " . get_route_count($suite) . " routes\n";
        echo "$separator\n$header\n$separator\n";
        foreach ($rows as $rank => $row) {
            $reg_ms   = $row['register_time_ms'] ?? 0;
            $cold_rps = $reg_ms > 0 ? 1000 / $reg_ms : 0;
            echo sprintf($format, $rank + 1, $row['router'], $row['mode'],
                number_format($row['lookups_per_second']),
                number_format($cold_rps),
                number_format($row['peak_memory_kb'], 1),
                number_format($row['register_memory_kb'] ?? 0, 1),
                number_format($reg_ms, 3)) . "\n";
        }
        echo "$separator\n";
    }
}

function save_results_as_markdown(array $results, int $seed): string
{
    $directory = __DIR__ . '/results';
    if (!is_dir($directory)) mkdir($directory, 0755, true);

    $cpu = null;
    if (is_readable('/proc/cpuinfo') && preg_match('/^model name\s*:\s*(.+)$/m', file_get_contents('/proc/cpuinfo'), $m)) {
        $cpu = trim($m[1]);
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        $result = shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null');
        if ($result !== null && $result !== '') $cpu = trim($result);
    }

    $lines = ["## Benchmarks", ""];

    array_push($lines,
        "Each suite provides a set of URL paths. For each path, 1-3 HTTP methods are assigned using a weighted " .
        "distribution (GET 60%, POST 25%, PUT 10%, DELETE 5%) to reflect typical API traffic patterns. " .
        "Dynamic segments are pre-filled with random slugs or integers, seeded for reproducibility.",
        "",
        "Lookups are drawn from a pre-generated list that follows a Zipf-like frequency distribution (exponent 0.9), " .
        "where a small number of routes receive the majority of traffic to simulate real-world hot-path behavior instead " .
        "of a uniform distribution. The list contains at least 2000 entries or 5x the route count, shuffled using the same seed.",
        "",
        "Each router is benchmarked inside PHP's built-in web server under multiple configurations " .
        "to capture steady-state throughput. Each combination is warmed up before measurement, and registration time " .
        "is averaged over multiple samples to reduce noise.",
        "",
    );

    array_push($lines, "### Setup", "");
    $setup_rows = array_filter([
        ['Date',        date('Y-m-d H:i:s')],
        ['CPU',         $cpu],
        ['PHP',         PHP_VERSION],
        ['Suites',      implode(', ', array_unique(array_column($results, 'suite')))],
        ['Routers',     implode(', ', array_unique(array_column($results, 'router')))],
        ['Modes',       implode(', ', array_unique(array_column($results, 'mode')))],
        ['Seed',        $seed],
    ], fn($row) => $row[1] !== null || $row[0] !== null);

    foreach ($setup_rows as [$key, $value]) {
        if ($value !== null) $lines[] = "- **$key:** $value";
    }

    array_push($lines,
        "### Column Reference", "",
        "| Column | Description |",
        "|:-------|:------------|",
        "| **RPS** | Per second throughput for routes that are registered once and reused across many requests |",
        "| **Cold RPS** | Estimated per second throughput if the router is re-bootstrapped on every request (1000 / Boot) |",
        "| **Peak (KB)** | Peak memory during the lookup benchmark |",
        "| **Reg (KB)** | Memory consumed by route registration |",
        "| **Boot (ms)** | Time to register all routes and complete the first lookup, including autoload overhead |",
        "",
        "### Results", "",
    );

    $by_suite = [];
    foreach ($results as $row) $by_suite[$row['suite']][] = $row;

    foreach ($by_suite as $suite => $rows) {
        usort($rows, fn($a, $b) => $b['lookups_per_second'] <=> $a['lookups_per_second']);
        array_push($lines,
            "#### $suite (" . get_route_count($suite) . " routes)", "",
            "| Rank | Router | Mode | RPS | Cold RPS | Peak (KB) | Reg (KB) | Boot (ms) |",
            "|-----:|:-------|:-----|----------:|---------:|--------------:|-------------:|----------:|",
        );
        foreach ($rows as $rank => $row) {
            $reg_ms = $row['register_time_ms'] ?? 0;
            $cold_rps = $reg_ms > 0 ? 1000 / $reg_ms : 0;
            $lines[] = sprintf("| %d | **%s** | %s | %s | %s | %s | %s | %s |",
                $rank + 1,
                $row['router'],
                $row['mode'],
                number_format($row['lookups_per_second']),
                number_format($cold_rps),
                number_format($row['peak_memory_kb'], 1),
                number_format($row['register_memory_kb'] ?? 0, 1),
                number_format($reg_ms, 3)
            );
        }
        $lines[] = "";
    }

    $path = "$directory/benchmark_" . date('Y-m-d_H-i-s') . ".md";
    file_put_contents($path, implode("\n", $lines) . "\n");
    return $path;
}

//
// CLI argument parsing
//

function resolve_option(string $name, ?string $csv, array $lookup): array
{
    if ($csv === null) return array_values($lookup);
    $selected = [];
    foreach (array_map('trim', str_getcsv($csv)) as $key) {
        if (!array_key_exists($key, $lookup)) {
            echo "Error: Unknown $name '$key'.\n";
            exit(1);
        }
        $selected[] = $lookup[$key];
    }
    return $selected ?: array_values($lookup);
}

function print_usage(array $suites, array $routers, array $modes): void
{
    echo "\nUsage: php " . basename(__FILE__) . " [--suite=...] [--router=...] [--mode=...] [--all]\n";
    echo "\nOptions:\n";
    foreach ([
        '--suite'        => 'Comma-separated list of test suites (default: all)',
        '--router'       => 'Comma-separated list of routers (default: all)',
        '--mode'         => 'Comma-separated list of benchmark modes (default: JIT=tracing, OPcache)',
        '--all'          => 'Run all suites, routers, and modes',
        '--duration'     => 'Seconds per benchmark combination (default: 1.0)',
        '--warmup'       => 'Seconds for warmup per combination, 0 to skip (default: 1.0)',
        '--reg-samples'  => 'Registration time sample count (default: 10)',
        '--reg-duration' => 'Minimum seconds per registration sample (default: 0.1)',
        '--seed'         => 'Seed for randomized lookup order and method assignment (default: 42)',
        '--help'         => 'Show this help screen',
    ] as $option => $description) {
        echo '  ' . str_pad($option, 15) . " $description\n";
    }
    echo "\nAvailable suites:  " . implode(', ', array_keys($suites)) . "\n";
    echo "Available routers: " . implode(', ', array_keys($routers)) . "\n";
    echo "Available modes:   " . implode(', ', array_keys($modes)) . "\n";
}

//
// Configuration
//

$suite_names = array_map(fn($file) => basename($file, '.php'), glob(__DIR__ . '/routes/*.php'));
$available_suites = array_combine($suite_names, $suite_names);

$available_routers = [];
foreach (glob(__DIR__ . '/Routers/*.php') as $file) {
    $class = 'Wilaak\\Http\\RadixRouter\\Benchmark\\Routers\\' . basename($file, '.php');
    if (class_exists($class) && is_a($class, RouterInterface::class, true)) {
        $available_routers[normalize_router_name($class::details()['name'])] = $class;
    }
}

$available_modes = [
    'JIT=tracing' => ['JIT=tracing', '-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit_buffer_size=100M -d opcache.jit=tracing'],
    'OPcache'     => ['OPcache',     '-d zend_extension=opcache -d opcache.enable=1 -d opcache.enable_cli=1'],
    'No OPcache'  => ['No OPcache',  '-d opcache.enable=0'],
];

//
// Argument parsing
//

$options = getopt('', ['suite::', 'router::', 'mode::', 'all', 'help', 'duration::', 'warmup::', 'reg-samples::', 'reg-duration::', 'seed::']);

if (isset($options['help'])) {
    print_usage($available_suites, $available_routers, $available_modes);
    exit(0);
}

if (!isset($options['all']) && !isset($options['suite']) && !isset($options['router']) && !isset($options['mode'])) {
    print_usage($available_suites, $available_routers, $available_modes);
    exit(1);
}

$suites  = resolve_option('suite',  $options['suite']  ?? null, $available_suites);
$routers = resolve_option('router', $options['router'] ?? null, $available_routers);
$modes   = resolve_option('mode',   $options['mode']   ?? null, [$available_modes['JIT=tracing'], $available_modes['OPcache']]);

$benchmark_duration = (float) ($options['duration']     ?? 1.0);
$warmup_duration    = (float) ($options['warmup']       ?? 1.0);
$reg_samples        = (int)   ($options['reg-samples']  ?? 10);
$reg_duration       = (float) ($options['reg-duration'] ?? 0.1);
$seed               = (int)   ($options['seed']         ?? 42);

//
// Pre-generate and cache route data
//

$router_names = array_map(fn($class) => normalize_router_name($class::details()['name']), $routers);

foreach ($suites as $suite) {
    foreach ($routers as $index => $router_class) {
        generate_routes_for_router($router_names[$index], $suite, $seed);
    }
    generate_lookup_list($suite, $seed, $router_names[0]);
    foreach ($routers as $index => $router_class) {
        adapt_router_routes($router_class, $router_names[$index], $suite, $seed);
    }
}

//
// Run benchmarks
//

echo "Suites:  " . implode(', ', $suites) . "\n";
echo "Routers: " . implode(', ', $router_names) . "\n";
echo "Modes:   " . implode(', ', array_column($modes, 0)) . "\n";
echo "Seed:    $seed\n";

$servers = [];
foreach ($modes as [$mode_label, $php_options]) {
    $port = get_unused_port();
    $servers[$mode_label] = ['port' => $port, 'pid' => start_web_server($port, $php_options)];
    echo "  Server started: $mode_label -> port $port\n";
}

if ($warmup_duration > 0.0) {
    run_benchmarks("Warming up", $suites, $routers, $modes, $servers, null, $warmup_duration, $seed, false);
}

$results = run_benchmarks("Running benchmarks", $suites, $routers, $modes, $servers, null, $benchmark_duration, $seed, true);
benchmark_registration($results, $servers, $reg_samples, $reg_duration, $seed);

print_results_table($results);
$markdown_path = save_results_as_markdown($results, $seed);
echo "\nResults saved to: $markdown_path\n";
