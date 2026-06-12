<?php

function render_throughput_svg(array $results, string $title = '', string $subtitle = ''): string
{
    $rows  = _svg_group_sort($results, fn($r) => (float) $r['lookups_per_second'], true);
    $value = fn($r) => max(0.0, (float) $r['lookups_per_second']);
    $label = function ($r) {
        $lps = max(0.0, (float) $r['lookups_per_second']);
        return $lps > 0
            ? sprintf('%s/s  (%s ns/op)', svg_compact_3sig($lps), number_format(1_000_000_000 / $lps))
            : svg_compact_3sig($lps) . '/s';
    };
    return render_metric_svg($rows, $title, $subtitle, $value, $label, 'svg_compact_number');
}

function render_registration_svg(array $results, string $title = '', string $subtitle = ''): string
{
    $rows  = _svg_group_sort($results, fn($r) => (float) ($r['register_time_ms'] ?? INF), false);
    $value = fn($r) => (float) ($r['register_time_ms'] ?? 0);
    $label = fn($r) => ($r['register_time_ms'] ?? null) === null
        ? '—'
        : number_format((float) $r['register_time_ms'], 2) . ' ms';
    $tick  = fn($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return render_metric_svg($rows, $title, $subtitle, $value, $label, $tick);
}

function render_memory_svg(array $results, string $title = '', string $subtitle = ''): string
{
    $rows  = _svg_group_sort($results, fn($r) => (float) ($r['peak_memory_kb'] ?? 0), false);
    $value = fn($r) => (float) ($r['peak_memory_kb'] ?? 0);
    $label = fn($r) => number_format($r['peak_memory_kb'] ?? 0) . ' KB (boot ' . number_format($r['register_memory_kb'] ?? 0) . ')';
    return render_metric_svg($rows, $title, $subtitle, $value, $label, 'svg_compact_number');
}

function render_metric_svg(
    array $results,
    string $title,
    string $subtitle,
    callable $valueOf,
    callable $labelOf,
    callable $tickFormat
): string {
    $palette = ['#474A8A', '#787CB5', '#B0B3D6'];

    $modes = [];
    foreach ($results as $row) {
        if (!isset($modes[$row['mode']])) $modes[$row['mode']] = count($modes);
    }

    $max_value = 0.0;
    foreach ($results as $row) $max_value = max($max_value, (float) $valueOf($row));
    [$step, $tick_count, $axis_max] = svg_nice_axis($max_value);

    $single_mode = count($modes) === 1;
    $bar_h   = 18;
    $bar_gap = 6;
    $row_h   = $bar_h + $bar_gap;
    $group_gap = 14;
    $text_dy = (int) round($bar_h * 0.5 + 4);

    $clusters = [];
    foreach ($results as $r) {
        $last = array_key_last($clusters);
        if ($last !== null && $clusters[$last]['router'] === $r['router']) {
            $clusters[$last]['rows'][] = $r;
        } else {
            $clusters[] = ['router' => $r['router'], 'rows' => [$r]];
        }
    }

    $left    = 150;
    $right   = 160;
    $chart_w = 560;
    $width   = $left + $chart_w + $right;

    $legend = [];
    foreach (array_keys($modes) as $i => $name) $legend[] = [$name, $palette[$i % count($palette)]];
    $show_legend = !$single_mode;

    $pad_top        = 16;
    $title_h        = $title === '' ? 0 : ($subtitle === '' ? 24 : 42);
    $legend_y       = $pad_top + $title_h + 4;
    $legend_col_w   = 140;
    $legend_per_row = max(1, (int) floor(($width - 40) / $legend_col_w));
    $legend_rows    = $show_legend ? (int) ceil(count($legend) / $legend_per_row) : 0;
    $top            = $legend_y + $legend_rows * 22 + 8;

    $n = count($results);
    $c = count($clusters);
    $bars_span   = $n * $row_h + max(0, $c - 1) * $group_gap;
    $axis_y      = $top + 12;
    $grid_top    = $axis_y + 5;
    $plot_top    = $top + 24;
    $grid_bottom = $plot_top + $bars_span - $bar_gap;
    $height      = $grid_bottom + 12;

    $unit = $chart_w / max($axis_max, 1e-9);
    $esc  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1);

    $out = [];
    $out[] = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" font-family="system-ui, -apple-system, \'Segoe UI\', sans-serif">',
        $width, $height
    );
    // Transparent background; only the text colors adapt to dark mode, the
    // bars and gridlines are legible on either.
    $out[] = '<style>'
        . '.fg{fill:#000000}.accent{fill:#474A8A}'
        . '.bar{shape-rendering:crispEdges}.div{stroke:#C9CBE6;stroke-width:1}.tick{fill:#787CB5}'
        . '@media(prefers-color-scheme:dark){.fg{fill:#E6E6E6}.accent{fill:#B0B3D6}.tick{fill:#B0B3D6}}'
        . '</style>';

    if ($title !== '') {
        $out[] = sprintf('<text class="accent" x="20" y="%d" font-size="14" font-weight="600">%s</text>',
            $pad_top + 14, $esc($title));
        if ($subtitle !== '') {
            $out[] = sprintf('<text class="fg" x="20" y="%d" font-size="12">%s</text>',
                $pad_top + 32, $esc($subtitle));
        }
    }

    if ($show_legend) {
        foreach ($legend as $i => [$name, $lcolor]) {
            $row = (int) ($i / $legend_per_row);
            $col = $i % $legend_per_row;
            $lx  = 20 + $col * $legend_col_w;
            $ly  = $legend_y + $row * 22;
            $out[] = sprintf('<rect x="%d" y="%d" width="14" height="14" fill="%s"/>', $lx, $ly, $lcolor);
            $out[] = sprintf('<text class="fg" x="%d" y="%d" font-size="12">%s</text>', $lx + 20, $ly + 12, $esc($name));
        }
    }

    for ($t = 0; $t <= $tick_count; $t++) {
        $x = $left + $t * $step * $unit;
        if ($t > 0) {
            $out[] = sprintf('<line class="div" x1="%.2f" y1="%.1f" x2="%.2f" y2="%.1f"/>',
                $x, $grid_top, $x, $grid_bottom);
        }
        $out[] = sprintf('<text class="tick" x="%.2f" y="%.1f" text-anchor="middle" font-size="11">%s</text>',
            $x, $axis_y, $esc($tickFormat($t * $step)));
    }

    $out[] = sprintf('<line class="div" x1="%d" y1="%.1f" x2="%d" y2="%.1f"/>',
        $left, $grid_top, $left, $grid_bottom);

    $y = $plot_top;
    foreach ($clusters as $ci => $cluster) {
        if ($ci > 0) $y += $group_gap;

        $cluster_h = count($cluster['rows']) * $row_h - $bar_gap;
        $out[] = sprintf('<text class="fg" x="%d" y="%.1f" text-anchor="end" font-size="12">%s</text>',
            $left - 12, $y + $cluster_h / 2 + 4.5, $esc(strtolower($cluster['router'])));

        foreach ($cluster['rows'] as $r) {
            $color = $palette[$modes[$r['mode']] % count($palette)];
            $out[] = sprintf('<rect class="bar" x="%d" y="%.1f" width="%.2f" height="%d" fill="%s"/>',
                $left, $y, max(0.0, (float) $valueOf($r)) * $unit, $bar_h, $color);
            $out[] = sprintf('<text class="fg" x="%d" y="%.1f" font-size="11">%s</text>',
                $left + $chart_w + 12, $y + $text_dy, $esc($labelOf($r)));
            $y += $row_h;
        }
    }

    $out[] = '</svg>';
    return implode("\n", $out) . "\n";
}

// Keeps each router's modes in their original order so the mode -> color
// mapping stays identical across every chart.
function _svg_group_sort(array $rows, callable $metric, bool $descending): array
{
    if ($rows === []) return [];
    $by_router = [];
    foreach ($rows as $r) $by_router[$r['router']][] = $r;
    $best = fn(array $group) => $descending ? max(array_map($metric, $group)) : min(array_map($metric, $group));
    uasort($by_router, fn($a, $b) => $descending ? $best($b) <=> $best($a) : $best($a) <=> $best($b));
    return array_merge(...array_values($by_router));
}

function svg_nice_axis(float $max): array
{
    if ($max <= 0) return [1.0, 1, 1.0];
    $rough = $max / 6;
    $exp   = (int) floor(log10($rough));
    $base  = 10 ** $exp;
    $f     = $rough / $base;
    $nice  = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10));
    $step  = $nice * $base;
    $count = (int) ceil($max / $step);
    return [$step, $count, $step * $count];
}

function svg_compact_3sig(float $v): string
{
    foreach ([1_000_000 => 'M', 1_000 => 'K'] as $div => $suffix) {
        if ($v >= $div) {
            $scaled = $v / $div;
            $decimals = $scaled >= 100 ? 0 : ($scaled >= 10 ? 1 : 2);
            return number_format($scaled, $decimals) . $suffix;
        }
    }
    return (string) (int) $v;
}

function svg_compact_number(float $v): string
{
    if ($v >= 1_000_000) return rtrim(rtrim(number_format($v / 1_000_000, 1, '.', ''), '0'), '.') . 'M';
    if ($v >= 1_000)     return rtrim(rtrim(number_format($v / 1_000,     1, '.', ''), '0'), '.') . 'K';
    return (string) (int) $v;
}
