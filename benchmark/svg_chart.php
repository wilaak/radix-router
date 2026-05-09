<?php

// Renders a single suite's benchmark results as a horizontal bar chart SVG.
// Bars are labeled by router and colored by mode.

function render_results_svg(array $results): string
{
    $palette = ['#474A8A', '#787CB5', '#B0B3D6'];

    $modes = [];
    foreach ($results as $row) {
        if (!isset($modes[$row['mode']])) $modes[$row['mode']] = count($modes);
    }
    $mode_names = array_keys($modes);

    $max_value = 0.0;
    foreach ($results as $row) $max_value = max($max_value, (float) $row['lookups_per_second']);
    [$step, $tick_count, $axis_max] = svg_nice_axis($max_value);

    $bar_h     = 18;
    $bar_gap   = 0;
    $group_gap = 8;
    $pad_y     = 10;
    $text_dy   = (int) round($bar_h * 0.5 + 4);

    $clusters = [];
    foreach ($results as $r) {
        $last = array_key_last($clusters);
        if ($last !== null && $clusters[$last]['router'] === $r['router']) {
            $clusters[$last]['rows'][] = $r;
        } else {
            $clusters[] = ['router' => $r['router'], 'rows' => [$r]];
        }
    }

    $left    = 140;
    $right   = 90;
    $chart_w = 600;
    $width   = $left + $chart_w + $right;

    $legend_col_w   = 140;
    $legend_per_row = max(1, (int) floor(($width - 40) / $legend_col_w));
    $legend_rows    = (int) ceil(count($modes) / $legend_per_row);
    $top            = 16 + $legend_rows * 22 + 12;

    $n = count($results);
    $chart_h = $n * $bar_h + max(0, $n - 1) * $bar_gap
             + max(0, count($clusters) - 1) * ($group_gap - $bar_gap)
             + 2 * $pad_y;
    $height  = $top + $chart_h + 60;

    $unit = $chart_w / max($axis_max, 1);
    $esc  = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1);

    $out = [];
    $out[] = sprintf(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" font-family="system-ui, -apple-system, \'Segoe UI\', sans-serif">',
        $width, $height
    );
    $out[] = '<style>'
        . '.bg{fill:#FFFFFF}.fg{fill:#000000}'
        . '.bar{shape-rendering:crispEdges}'
        . '@media(prefers-color-scheme:dark){.bg{fill:#1E1E1E}.fg{fill:#E6E6E6}}'
        . '</style>';
    $out[] = sprintf('<rect class="bg" width="%d" height="%d"/>', $width, $height);

    foreach ($mode_names as $i => $name) {
        $row = (int) ($i / $legend_per_row);
        $col = $i % $legend_per_row;
        $lx  = 20 + $col * $legend_col_w;
        $ly  = 20 + $row * 22;
        $out[] = sprintf('<rect x="%d" y="%d" width="14" height="14" fill="%s"/>',
            $lx, $ly, $palette[$i % count($palette)]);
        $out[] = sprintf('<text class="fg" x="%d" y="%d" font-size="12">%s</text>',
            $lx + 20, $ly + 12, $esc($name));
    }

    for ($t = 0; $t <= $tick_count; $t++) {
        $x = $left + $t * $step * $unit;
        $out[] = sprintf('<text class="fg" x="%.2f" y="%d" text-anchor="middle" font-size="11">%s</text>',
            $x, $top + $chart_h + 18, $esc(svg_axis_label($t * $step)));
    }
    $out[] = sprintf('<text class="fg" x="%.2f" y="%d" text-anchor="middle" font-size="12" font-weight="600">Lookups / second</text>',
        $left + $chart_w / 2, $top + $chart_h + 40);

    $y = $top + $pad_y;
    foreach ($clusters as $ci => $cluster) {
        if ($ci > 0) $y += $group_gap - $bar_gap;
        $rows  = $cluster['rows'];
        $count = count($rows);
        $cluster_h = $count * $bar_h + max(0, $count - 1) * $bar_gap;

        $out[] = sprintf('<text class="fg" x="%d" y="%.1f" text-anchor="end" font-size="12" font-weight="600">%s</text>',
            $left - 8, $y + $cluster_h / 2 + 4.5, $esc($cluster['router']));

        foreach ($rows as $r) {
            $color = $palette[$modes[$r['mode']] % count($palette)];
            $bw    = max(0.0, (float) $r['lookups_per_second']) * $unit;
            $out[] = sprintf('<rect class="bar" x="%d" y="%.1f" width="%.2f" height="%d" fill="%s"/>',
                $left, $y, $bw, $bar_h, $color);
            $out[] = sprintf('<text class="fg" x="%.2f" y="%.1f" font-size="11">%s</text>',
                $left + $bw + 6, $y + $text_dy, $esc(number_format((float) $r['lookups_per_second'])));
            $y += $bar_h + $bar_gap;
        }
    }

    $out[] = '</svg>';
    return implode("\n", $out) . "\n";
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

function svg_axis_label(float $v): string
{
    if ($v >= 1_000_000) return rtrim(rtrim(number_format($v / 1_000_000, 1, '.', ''), '0'), '.') . 'M';
    if ($v >= 1_000)     return rtrim(rtrim(number_format($v / 1_000,     1, '.', ''), '0'), '.') . 'K';
    return (string) (int) $v;
}
