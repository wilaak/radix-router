<?php

declare(strict_types=1);

namespace DartRouter;

if (\PHP_INT_SIZE !== 8) {
    throw new \RuntimeException('DartRouter requires 64-bit PHP; PHP_INT_SIZE is ' . \PHP_INT_SIZE . '.');
}

// Row-displacement-compressed radix trie router
//
// The trie is flattened into two parallel arrays indexed by (node_displacement + path_byte):
//
//   slots[index]   packed integer slot
//   edges[index]   multi-byte edge label, or null
//
// Byte 0 is reserved as the per-node sentinel; real path bytes are never 0
// because paths are '/'-delimited.
//
// Slot bit layout (64-bit, byte-aligned):
//
//   bits 56..63  segment offset - bytes consumed past the last '/' boundary
//                                 (param-fallback sentinels only); lets the
//                                 matcher rewind the cursor to the segment
//                                 start when a mid-segment intermediate
//                                 takes the param branch.
//   bits 40..55  route id      - 16-bit handler index (terminal sentinels only)
//   bits 32..39  flag byte     - sentinel-only flags (see FLAG_* constants)
//   bits 16..31  child base    - displacement of the destination node
//   bits  8..15  edge length   - 0           sentinel-only slot
//                                1           single-byte edge (no edges[] entry)
//                                2..254      multi-byte edge (edges[] holds label)
//                                0xFF        param-fallback sentinel
//   bits  0..7   owner byte    - first byte of the edge owning this slot;
//                                0 marks a sentinel. Lets the matcher detect
//                                foreign rows that alias after displacement.

final readonly class Slot
{
    public const OWNER_MASK           = 0xFF;

    public const LENGTH_SHIFT         = 8;
    public const LENGTH_MASK          = 0xFF;
    public const LENGTH_FIELD_MASK    = 0xFF00;

    public const LENGTH_SENTINEL_ONLY = 0x00;
    public const LENGTH_SINGLE_BYTE   = 0x01;
    public const LENGTH_EDGE_MAX      = 0xFE;
    public const LENGTH_PARAM         = 0xFF;

    public const CHILD_SHIFT          = 16;
    public const CHILD_MASK           = 0xFFFF;

    public const FLAG_TERMINAL        = 1 << 32;
    public const FLAG_TRAILING_SLASH  = 1 << 33;

    public const ROUTE_ID_SHIFT       = 40;
    public const ROUTE_ID_MASK        = 0xFFFF;

    public const SEGMENT_OFFSET_SHIFT = 56;
    public const SEGMENT_OFFSET_MASK  = 0xFF;
}

// Runtime data: built tables, registered patterns, and per-route handlers.
final class Router
{
    /** @var array<int, int> */
    public array $slots = [];
    /** @var array<int, ?string> */
    public array $edges = [];
    /** @var string[] */
    public array $patterns = [];
    /** @var array<int, mixed> indexed by route_id (parallel to $patterns) */
    public array $handlers = [];
}

final class _Node
{
    /** @var array<int, array{string, int}> [byte] => [edge_string, child_node_id] */
    public array $children = [];
    /** child node id, or -1 */
    public int $param_child_id = -1;
    public bool $terminal = false;
    public bool $trailing_slash_allowed = false;
    /** route_id at terminal nodes, or -1 */
    public int $route_id = -1;

    public function __construct(
        /** sits just past a '/' (or at root) */
        public bool $at_segment_boundary,
        /** bytes consumed past the last '/' boundary on the path to this node */
        public int $segment_offset = 0,
    ) {}
}

function lookup(Router $router, string $path): ?array
{
    $slots    = $router->slots;
    $edges    = $router->edges;
    $handlers = $router->handlers;

    $length = \strlen($path);
    $base   = 0;
    $cursor = 1;
    $params = [];

    while ($cursor < $length) {
        $byte  = \ord($path[$cursor]);
        $index = $base + $byte;
        $slot  = $slots[$index];

        if (($slot & Slot::OWNER_MASK) === $byte) {
            $child_base  = ($slot >> Slot::CHILD_SHIFT)  & Slot::CHILD_MASK;
            $edge_length = ($slot >> Slot::LENGTH_SHIFT) & Slot::LENGTH_MASK;

            if ($edge_length === Slot::LENGTH_SINGLE_BYTE) {
                $base = $child_base;
                $cursor++;
                continue;
            }

            if (\substr_compare($path, $edges[$index], $cursor, $edge_length) === 0) {
                $base    = $child_base;
                $cursor += $edge_length;
                continue;
            }
        }

        $sentinel = $slots[$base];
        if (($sentinel & Slot::LENGTH_FIELD_MASK) === (Slot::LENGTH_PARAM << Slot::LENGTH_SHIFT)) {
            $end = \strpos($path, '/', $cursor);
            if ($end === false) {
                $end = $length;
            }
            if ($end === $cursor) {
                return null;
            }
            // Mid-segment intermediates inherit a param target via
            // _compile_propagate_params; rewind by the segment offset so the
            // emitted param covers the static prefix already consumed, not
            // just the trailing bytes after this node.
            $segment_offset = ($sentinel >> Slot::SEGMENT_OFFSET_SHIFT) & Slot::SEGMENT_OFFSET_MASK;
            $segment_start  = $cursor - $segment_offset;
            $params[]       = \substr($path, $segment_start, $end - $segment_start);
            $base   = ($sentinel >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
            $cursor = $end + 1;
            continue;
        }

        if (
            $cursor === $length - 1
            && $byte === 0x2F
            && ($sentinel & Slot::FLAG_TRAILING_SLASH) !== 0
        ) {
            $route_id = ($sentinel >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
            return [$handlers[$route_id], $params];
        }

        return null;
    }

    $sentinel        = $slots[$base];
    $is_own_sentinel = ($sentinel & Slot::OWNER_MASK)    === 0;
    $is_terminal     = ($sentinel & Slot::FLAG_TERMINAL) !== 0;

    if (!$is_own_sentinel || !$is_terminal) {
        return null;
    }
    $route_id = ($sentinel >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
    return [$handlers[$route_id], $params];
}

function add(Router $router, string $pattern, mixed $handler = null): int
{
    $route_id = \count($router->patterns);
    $router->patterns[] = $pattern;
    $router->handlers[] = $handler;
    return $route_id;
}

function compile(Router $router): void
{
    /** @var _Node[] $nodes */
    $nodes = [new _Node(true)];
    foreach ($router->patterns as $route_id => $pattern) {
        _compile_insert($nodes, $pattern, $route_id);
    }
    _compile_propagate_params($nodes, 0, -1);
    _compile_propagate_slash_terminals($nodes);
    [$router->slots, $router->edges] = _compile_emit_packed($nodes);
}

function _compile_insert(array &$nodes, string $pattern, int $route_id): void
{
    $normalized = \rtrim($pattern, '/');
    $segments   = $normalized === '' ? [] : \explode('/', \substr($normalized, 1));

    $node_id       = 0;
    $static_buffer = '';
    foreach ($segments as $segment) {
        if (\str_starts_with($segment, ':')) {
            if ($static_buffer !== '') {
                $node_id = _compile_insert_static($nodes, $node_id, $static_buffer);
                $static_buffer = '';
            }
            if ($nodes[$node_id]->param_child_id < 0) {
                $nodes[$node_id]->param_child_id = _compile_new_node($nodes, true);
            }
            $node_id = $nodes[$node_id]->param_child_id;
        } else {
            $static_buffer .= $segment . '/';
        }
    }
    if ($static_buffer !== '') {
        // Drop trailing '/'; trailing-slash tolerance is encoded as
        // Slot::FLAG_TRAILING_SLASH on the sentinel rather than a duplicate edge.
        $node_id = _compile_insert_static($nodes, $node_id, \substr($static_buffer, 0, -1));
        $nodes[$node_id]->trailing_slash_allowed = true;
    }
    $nodes[$node_id]->terminal = true;
    $nodes[$node_id]->route_id = $route_id;
}

function _compile_insert_static(array &$nodes, int $node_id, string $edge): int
{
    while (true) {
        $first_byte = \ord($edge[0]);
        if (!isset($nodes[$node_id]->children[$first_byte])) {
            $new_node_id = _compile_new_node($nodes, true);
            $nodes[$node_id]->children[$first_byte] = [$edge, $new_node_id];
            return $new_node_id;
        }

        [$existing_edge, $child_node_id] = $nodes[$node_id]->children[$first_byte];
        $new_length      = \strlen($edge);
        $existing_length = \strlen($existing_edge);
        $compare_length  = $new_length < $existing_length ? $new_length : $existing_length;
        $prefix_length   = 0;
        while ($prefix_length < $compare_length && $edge[$prefix_length] === $existing_edge[$prefix_length]) {
            $prefix_length++;
        }

        if ($prefix_length === $existing_length) {
            if ($prefix_length === $new_length) {
                return $child_node_id;
            }
            $node_id = $child_node_id;
            $edge    = \substr($edge, $prefix_length);
            continue;
        }

        $existing_suffix     = \substr($existing_edge, $prefix_length);
        $is_boundary         = $existing_edge[$prefix_length - 1] === '/';
        $intermediate_offset = $is_boundary
            ? 0
            : _compile_advance_offset($nodes[$node_id]->segment_offset, \substr($existing_edge, 0, $prefix_length));
        $intermediate        = _compile_new_node($nodes, $is_boundary, $intermediate_offset);
        $nodes[$intermediate]->children[\ord($existing_suffix[0])] = [$existing_suffix, $child_node_id];
        $nodes[$node_id]->children[$first_byte] = [\substr($existing_edge, 0, $prefix_length), $intermediate];

        if ($prefix_length === $new_length) {
            return $intermediate;
        }

        $new_suffix  = \substr($edge, $prefix_length);
        $new_node_id = _compile_new_node($nodes, true);
        $nodes[$intermediate]->children[\ord($new_suffix[0])] = [$new_suffix, $new_node_id];
        return $new_node_id;
    }
}

function _compile_new_node(array &$nodes, bool $at_segment_boundary, int $segment_offset = 0): int
{
    $id = \count($nodes);
    $nodes[$id] = new _Node($at_segment_boundary, $segment_offset);
    return $id;
}

// Bytes consumed past the last '/' after walking $consumed from a node at
// $parent_offset. Used to label split-intermediates with how far into the
// current segment they sit, so the matcher can rewind on a param fallback.
function _compile_advance_offset(int $parent_offset, string $consumed): int
{
    $last_slash = \strrpos($consumed, '/');
    if ($last_slash === false) {
        return $parent_offset + \strlen($consumed);
    }
    return \strlen($consumed) - $last_slash - 1;
}

// Mid-segment intermediates (created by edge splits at non-'/' boundaries)
// inherit the param-child of their nearest segment-boundary ancestor, so
// partial static matches can still fall through to a param transition.
function _compile_propagate_params(array &$nodes, int $node_id, int $inherited): void
{
    $node = $nodes[$node_id];
    if ($node->at_segment_boundary) {
        $next = $node->param_child_id;
    } else {
        if ($node->param_child_id < 0 && $inherited >= 0) {
            $node->param_child_id = $inherited;
        }
        $next = $inherited;
    }
    foreach ($node->children as [, $child_id]) {
        _compile_propagate_params($nodes, $child_id, $next);
    }
    if ($node->at_segment_boundary && $node->param_child_id >= 0) {
        _compile_propagate_params($nodes, $node->param_child_id, -1);
    }
}

// When a sibling forces a single-byte '/' edge out of a terminal node
// (e.g. '/foo' alongside '/foo/bar'), the matcher succeeds the '/'
// transition into a non-terminal intermediate. Mark it terminal so '/foo/'
// still matches via the static path rather than Slot::FLAG_TRAILING_SLASH.
function _compile_propagate_slash_terminals(array &$nodes): void
{
    foreach ($nodes as $node) {
        if (!$node->terminal || !$node->trailing_slash_allowed) {
            continue;
        }
        if (!isset($node->children[0x2F])) {
            continue;
        }
        [$edge, $child_node_id] = $node->children[0x2F];
        if ($edge === '/') {
            $nodes[$child_node_id]->terminal = true;
            $nodes[$child_node_id]->route_id = $node->route_id;
        }
    }
}

function _compile_emit_packed(array $nodes): array
{
    // Byte 0 is always included so a row's displacement is reserved in
    // used_slots without a separate used_displacements set.
    $occupied_bytes = [];
    foreach ($nodes as $node_id => $node) {
        $bytes   = \array_keys($node->children);
        $bytes[] = 0;
        $occupied_bytes[$node_id] = $bytes;
    }

    $node_displacement = _compile_place_nodes($nodes, $occupied_bytes);
    $size              = \max($node_displacement) + 256;

    $slots       = \array_fill(0, $size, 0);
    $edge_labels = \array_fill(0, $size, null);

    foreach ($nodes as $node_id => $node) {
        $displacement = $node_displacement[$node_id];

        foreach ($node->children as $byte => [$edge, $child_id]) {
            $edge_length = \strlen($edge);
            if ($edge_length > Slot::LENGTH_EDGE_MAX) {
                throw new \LengthException("Edge label too long ($edge_length bytes); max " . Slot::LENGTH_EDGE_MAX . '.');
            }
            $index = $displacement + $byte;
            $slots[$index] = $byte
                | ($edge_length                  << Slot::LENGTH_SHIFT)
                | ($node_displacement[$child_id] << Slot::CHILD_SHIFT);
            // Single-byte edges are reconstructable from the dispatched
            // byte; matcher never reads edge_labels[$index] for them.
            if ($edge_length === Slot::LENGTH_SINGLE_BYTE) {
                continue;
            }
            $edge_labels[$index] = $edge;
        }

        $has_handler   = $node->terminal;
        $param_node_id = $node->param_child_id;
        if ($has_handler || $param_node_id >= 0) {
            $has_param          = $param_node_id >= 0;
            $child_displacement = $has_param ? $node_displacement[$param_node_id] : 0;
            $route_id = $has_handler ? $node->route_id : 0;
            if ($route_id > Slot::ROUTE_ID_MASK) {
                throw new \LengthException("Too many routes ($route_id); max " . Slot::ROUTE_ID_MASK . '.');
            }
            $segment_offset = $node->segment_offset;
            if ($segment_offset > Slot::SEGMENT_OFFSET_MASK) {
                throw new \LengthException("Segment offset too large ($segment_offset bytes); max " . Slot::SEGMENT_OFFSET_MASK . '.');
            }
            $slots[$displacement] = (($has_param ? Slot::LENGTH_PARAM : Slot::LENGTH_SENTINEL_ONLY) << Slot::LENGTH_SHIFT)
                | ($child_displacement << Slot::CHILD_SHIFT)
                | ($has_handler ? Slot::FLAG_TERMINAL : 0)
                | ($has_handler && $node->trailing_slash_allowed ? Slot::FLAG_TRAILING_SLASH : 0)
                | ($route_id << Slot::ROUTE_ID_SHIFT)
                | ($segment_offset << Slot::SEGMENT_OFFSET_SHIFT);
        }
    }

    return [$slots, $edge_labels];
}

// First-fit displacement assignment. Root is fixed at 0; remaining nodes
// are placed in DFS order so a node and its descendants stay close in the
// packed table.
function _compile_place_nodes(array $nodes, array $occupied_bytes): array
{
    $displacement = [0 => 0];
    $used_slots   = [];
    foreach ($occupied_bytes[0] as $byte) {
        $used_slots[$byte] = true;
    }

    $order = [];
    $seen  = [0 => true];
    _compile_place_nodes_visit($nodes, 0, $order, $seen);

    // Frontier cursor: smallest displacement not yet reserved. Most placements
    // land at or just past it, so resuming from the frontier instead of 1
    // skips the densely-packed region near the root.
    $frontier = 1;

    foreach ($order as $node_id) {
        if ($node_id === 0) {
            continue;
        }
        $bytes = $occupied_bytes[$node_id];
        while (isset($used_slots[$frontier])) {
            $frontier++;
        }
        $candidate = $frontier;
        while (true) {
            $fits = true;
            foreach ($bytes as $byte) {
                if (isset($used_slots[$candidate + $byte])) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                $displacement[$node_id] = $candidate;
                foreach ($bytes as $byte) {
                    $used_slots[$candidate + $byte] = true;
                }
                break;
            }
            do {
                $candidate++;
            } while (isset($used_slots[$candidate]));
        }
    }

    return $displacement;
}

// DFS traversal that produces the order _compile_place_nodes() uses.
//
// After _compile_propagate_params(), intermediate non-boundary nodes share a
// param_child_id pointer with their boundary ancestor, so the param subtree
// is reachable along multiple DFS paths - $seen prevents double-placement.
function _compile_place_nodes_visit(array $nodes, int $node_id, array &$order, array &$seen): void
{
    $order[] = $node_id;

    $static_children = [];
    foreach ($nodes[$node_id]->children as $entry) {
        $static_children[] = $entry[1];
    }

    \sort($static_children);
    foreach ($static_children as $child_id) {
        if (!isset($seen[$child_id])) {
            $seen[$child_id] = true;
            _compile_place_nodes_visit($nodes, $child_id, $order, $seen);
        }
    }

    $param_child_id = $nodes[$node_id]->param_child_id;
    if ($param_child_id >= 0 && !isset($seen[$param_child_id])) {
        $seen[$param_child_id] = true;
        _compile_place_nodes_visit($nodes, $param_child_id, $order, $seen);
    }
}