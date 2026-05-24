<?php

declare(strict_types=1);

namespace DartRouterSimple;

// Plain radix-trie router. Nodes live in one flat packed array as a
// stride of 4 fields per node; child references are int node ids.
// Same matching semantics as DartRouter (param fallback with mid-segment
// rewind, trailing-slash tolerance), no objects in the trie itself.

// Per-node stride layout in $router->nodes.
//
// FIELD_PACKED bit layout (64-bit int):
//   bits  0-23  : route_id + 1      (0 = none)     — up to 16M routes
//   bits 24-47  : parameter_id + 1  (0 = none)     — up to 16M nodes
//   bits 48-63  : label_length                     — up to 64K
//
// Packing three small scalars into one slot collapses three array reads
// per descent into one; the shift/mask work is free under JIT, while every
// array read pays for an indexed hash lookup and a zval type dance.
final class Node
{
    public const STRIDE         = 4;
    public const FIELD_LABEL    = 0;   // string: edge label from parent (full, includes dispatch byte)
    public const FIELD_KEYS     = 1;   // string: dispatch byte for each child, concatenated
    public const FIELD_CHILDREN = 2;   // int[]:  child node ids, parallel to FIELD_KEYS
    public const FIELD_PACKED   = 3;   // int:    label_length | (parameter+1) | (route+1)

    public const ROUTE_MASK           = 0xFFFFFF;
    public const PARAMETER_SHIFT      = 24;
    public const PARAMETER_MASK       = 0xFFFFFF;
    public const LENGTH_SHIFT         = 48;
    public const LOW48_MASK           = 0xFFFFFFFFFFFF;
    public const PARAMETER_FIELD_MASK = 0xFFFFFF000000; // PARAMETER_MASK << PARAMETER_SHIFT
}

final class Router
{
    /** @var array flat: [label0, keys0, children0, packed0, label1, keys1, children1, packed1, ...] */
    public array $nodes = [];
    /** @var int[] segment-offset per node id; construction-only, not read in lookup */
    public array $segment_offsets = [];
    /** @var string[] */
    public array $patterns = [];
    /** @var mixed[] indexed by route_id */
    public array $handlers = [];
    public function __construct()
    {
        // Root node at id 0: empty label, no children, no route, no param, len=0.
        $this->nodes = ['', '', [], 0];
        $this->segment_offsets   = [0];
    }
}

function lookup(Router $router, string $path): ?array
{
    $nodes    = $router->nodes;
    $handlers = $router->handlers;

    $length = \strlen($path);
    if ($length > 1 && $path[$length - 1] === '/') {
        $length--;
    }

    $base                = 0;
    $cursor              = 1;
    $params              = null;
    $parameter_base          = -1;
    $parameter_segment_start = 0;

    while ($cursor < $length) {
        $packed = (int) $nodes[$base + Node::FIELD_PACKED];

        // F_PARAM is only ever set on boundary nodes (see add()), so a
        // non-zero param field implies "we're at a segment boundary AND
        // there is a param child" — no separate F_SEG check needed.
        $parameter_plus_one = ($packed >> Node::PARAMETER_SHIFT) & Node::PARAMETER_MASK;
        if ($parameter_plus_one !== 0) {
            $parameter_base          = ($parameter_plus_one - 1) * Node::STRIDE;
            $parameter_segment_start = $cursor;
        }

        $i = \strpos($nodes[$base + Node::FIELD_KEYS], $path[$cursor]);
        if ($i !== false) {
            $child_base   = $nodes[$base + Node::FIELD_CHILDREN][$i] * Node::STRIDE;
            $child_packed = (int) $nodes[$child_base + Node::FIELD_PACKED];
            $label_length    = $child_packed >> Node::LENGTH_SHIFT;
            if ($label_length === 1
                || \substr_compare($path, $nodes[$child_base + Node::FIELD_LABEL], $cursor, $label_length) === 0) {
                $base    = $child_base;
                $cursor += $label_length;
                continue;
            }
        }

        if ($parameter_base >= 0) {
            $end = \strpos($path, '/', $parameter_segment_start);
            if ($end === false || $end >= $length) {
                $end = $length;
            }
            if ($end === $parameter_segment_start) {
                return null;
            }
            $params[]   = \substr($path, $parameter_segment_start, $end - $parameter_segment_start);
            $base       = $parameter_base;
            $cursor     = $end + 1;
            $parameter_base = -1;
            continue;
        }

        return null;
    }

    $route_plus_one = $nodes[$base + Node::FIELD_PACKED] & Node::ROUTE_MASK;
    if ($route_plus_one === 0) {
        return null;
    }
    return [$handlers[$route_plus_one - 1], $params];
}

function add(Router $router, string $pattern, mixed $handler = null): int
{
    $route_id           = \count($router->patterns);
    $router->patterns[] = $pattern;
    $router->handlers[] = $handler;

    $normalized = \rtrim($pattern, '/');
    $segments   = $normalized === '' ? [] : \explode('/', \substr($normalized, 1));

    $node_id       = 0;
    $static_buffer = '';
    foreach ($segments as $segment) {
        if (\str_starts_with($segment, ':')) {
            if ($static_buffer !== '') {
                $node_id = _insert_static($router, $node_id, $static_buffer);
                $static_buffer = '';
            }
            $base           = $node_id * Node::STRIDE;
            $packed         = $router->nodes[$base + Node::FIELD_PACKED];
            $parameter_plus_one = ($packed >> Node::PARAMETER_SHIFT) & Node::PARAMETER_MASK;
            if ($parameter_plus_one === 0) {
                $parameter_id = _new_node($router, '', 0);
                $router->nodes[$base + Node::FIELD_PACKED] =
                    ($packed & ~Node::PARAMETER_FIELD_MASK)
                    | (($parameter_id + 1) << Node::PARAMETER_SHIFT);
                $node_id = $parameter_id;
            } else {
                $node_id = $parameter_plus_one - 1;
            }
        } else {
            $static_buffer .= $segment . '/';
        }
    }
    if ($static_buffer !== '') {
        $node_id = _insert_static($router, $node_id, \substr($static_buffer, 0, -1));
    }

    $base   = $node_id * Node::STRIDE;
    $packed = $router->nodes[$base + Node::FIELD_PACKED];
    $router->nodes[$base + Node::FIELD_PACKED] =
        ($packed & ~Node::ROUTE_MASK) | ($route_id + 1);

    return $route_id;
}

function _new_node(Router $router, string $label, int $segment_offset): int
{
    $id = \intdiv(\count($router->nodes), Node::STRIDE);
    $router->nodes[] = $label;
    $router->nodes[] = '';
    $router->nodes[] = [];
    $router->nodes[] = \strlen($label) << Node::LENGTH_SHIFT;
    $router->segment_offsets[$id] = $segment_offset;
    return $id;
}

// Walk a static edge into the trie, splitting existing edges on shared
// prefixes. Returns the node id that owns the terminal of $edge.
function _insert_static(Router $router, int $node_id, string $edge): int
{
    while (true) {
        $base       = $node_id * Node::STRIDE;
        $first_byte = $edge[0];
        $i          = \strpos($router->nodes[$base + Node::FIELD_KEYS], $first_byte);
        if ($i === false) {
            $child_id = _new_node(
                $router,
                $edge,
                _segment_offset_after($router->segment_offsets[$node_id], $edge),
            );
            $router->nodes[$base + Node::FIELD_KEYS] .= $first_byte;
            $router->nodes[$base + Node::FIELD_CHILDREN][] = $child_id;
            return $child_id;
        }

        $existing_id     = $router->nodes[$base + Node::FIELD_CHILDREN][$i];
        $existing_base   = $existing_id * Node::STRIDE;
        $existing_label  = $router->nodes[$existing_base + Node::FIELD_LABEL];
        $new_length      = \strlen($edge);
        $existing_length = \strlen($existing_label);
        $compare_length  = \min($new_length, $existing_length);
        $prefix_length   = 0;
        while ($prefix_length < $compare_length
            && $edge[$prefix_length] === $existing_label[$prefix_length]) {
            $prefix_length++;
        }

        if ($prefix_length === $existing_length) {
            if ($prefix_length === $new_length) {
                return $existing_id;
            }
            $node_id = $existing_id;
            $edge    = \substr($edge, $prefix_length);
            continue;
        }

        // Split: insert an intermediate that owns the shared prefix; the old
        // child becomes a grandchild with its label trimmed to the suffix.
        $shared_prefix = \substr($existing_label, 0, $prefix_length);
        $existing_tail = \substr($existing_label, $prefix_length);
        $intermediate_id = _new_node(
            $router,
            $shared_prefix,
            _segment_offset_after($router->segment_offsets[$node_id], $shared_prefix),
        );
        $intermediate_base = $intermediate_id * Node::STRIDE;

        // Trim the existing child's label to the suffix; update its cached len.
        $router->nodes[$existing_base + Node::FIELD_LABEL] = $existing_tail;
        $existing_packed = $router->nodes[$existing_base + Node::FIELD_PACKED];
        $router->nodes[$existing_base + Node::FIELD_PACKED] =
            ($existing_packed & Node::LOW48_MASK) | (\strlen($existing_tail) << Node::LENGTH_SHIFT);

        $router->nodes[$intermediate_base + Node::FIELD_KEYS] = $existing_tail[0];
        $router->nodes[$intermediate_base + Node::FIELD_CHILDREN] = [$existing_id];
        // Repoint the parent's slot from existing to intermediate.
        $router->nodes[$base + Node::FIELD_CHILDREN][$i] = $intermediate_id;

        if ($prefix_length === $new_length) {
            return $intermediate_id;
        }

        $new_tail = \substr($edge, $prefix_length);
        $new_child_id = _new_node(
            $router,
            $new_tail,
            _segment_offset_after($router->segment_offsets[$intermediate_id], $new_tail),
        );
        $router->nodes[$intermediate_base + Node::FIELD_KEYS] .= $new_tail[0];
        $router->nodes[$intermediate_base + Node::FIELD_CHILDREN][] = $new_child_id;
        return $new_child_id;
    }
}

function _segment_offset_after(int $parent_offset, string $edge): int
{
    $last_slash = \strrpos($edge, '/');
    return $last_slash === false
        ? $parent_offset + \strlen($edge)
        : \strlen($edge) - $last_slash - 1;
}
