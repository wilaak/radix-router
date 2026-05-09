<?php

declare(strict_types=1);

namespace DartRouter;

if (\PHP_INT_SIZE !== 8) {
    throw new \RuntimeException('DartRouter requires 64-bit PHP; PHP_INT_SIZE is ' . \PHP_INT_SIZE . '.');
}

// NOTE: THIS IS OVERCOMPLICATED SHIT, DO NOT USE THIS AS A REFERENCE FOR HOW TO WRITE A ROUTER.

// TODO: There is a bug hiding beneath something here.... fix it... one day maybe

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

    public const ROUTE_ID_SHIFT       = 40;
    public const ROUTE_ID_MASK        = 0xFFFF;

    public const SEGMENT_OFFSET_SHIFT = 56;
    public const SEGMENT_OFFSET_MASK  = 0xFF;
}

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
    /** route_id at terminal nodes, or -1; >= 0 also means "this node is terminal" */
    public int $route_id = -1;

    public function __construct(
        /**
         * Bytes consumed past the last '/' boundary on the path to this node.
         * 0 implies this node sits just past a '/' (or at root) - i.e., a
         * segment boundary. Non-zero implies a mid-segment intermediate.
         */
        public int $segment_offset = 0,
    ) {}
}

function lookup(Router $router, string $path): ?array
{
    $slots    = $router->slots;
    $edges    = $router->edges;
    $handlers = $router->handlers;

    $length = \strlen($path);
    // Trailing '/' tolerance: strip one and let the matcher reach the same
    // terminal node as the un-slashed path. Skipped for '/' itself.
    if ($length > 1 && $path[$length - 1] === '/') {
        $length--;
    }
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
            if ($end === false || $end >= $length) {
                $end = $length;
            }
            if ($end === $cursor) {
                return null;
            }
            // Mid-segment intermediates inherit a param target via the
            // param-propagation pass; rewind by the segment offset so the
            // emitted param covers the static prefix already consumed.
            $segment_offset = ($sentinel >> Slot::SEGMENT_OFFSET_SHIFT) & Slot::SEGMENT_OFFSET_MASK;
            $segment_start  = $cursor - $segment_offset;
            $params[]       = \substr($path, $segment_start, $end - $segment_start);
            $base   = ($sentinel >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
            $cursor = $end + 1;
            continue;
        }

        return null;
    }

    $sentinel = $slots[$base];
    if (($sentinel & Slot::OWNER_MASK) !== 0 || ($sentinel & Slot::FLAG_TERMINAL) === 0) {
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
    $nodes = [new _Node()];
    foreach ($router->patterns as $route_id => $pattern) {
        _compile_insert($nodes, $pattern, $route_id);
    }
    $order = [];
    $seen  = [0 => true];
    _compile_walk($nodes, 0, -1, $order, $seen);
    [$router->slots, $router->edges] = _compile_emit_packed($nodes, $order);
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
                $nodes[$node_id]->param_child_id = _compile_new_node($nodes);
            }
            $node_id = $nodes[$node_id]->param_child_id;
        } else {
            $static_buffer .= $segment . '/';
        }
    }
    if ($static_buffer !== '') {
        // Drop trailing '/'; the matcher's lookup-time strip handles the
        // tolerated trailing slash, so we don't store a duplicate edge.
        $node_id = _compile_insert_static($nodes, $node_id, \substr($static_buffer, 0, -1));
    }
    $nodes[$node_id]->route_id = $route_id;
}

function _compile_insert_static(array &$nodes, int $node_id, string $edge): int
{
    while (true) {
        $first_byte = \ord($edge[0]);
        if (!isset($nodes[$node_id]->children[$first_byte])) {
            $new_node_id = _compile_new_node($nodes, $nodes[$node_id]->segment_offset, $edge);
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

        $consumed_prefix = \substr($existing_edge, 0, $prefix_length);
        $intermediate    = _compile_new_node($nodes, $nodes[$node_id]->segment_offset, $consumed_prefix);
        $existing_suffix = \substr($existing_edge, $prefix_length);
        $nodes[$intermediate]->children[\ord($existing_suffix[0])] = [$existing_suffix, $child_node_id];
        $nodes[$node_id]->children[$first_byte] = [$consumed_prefix, $intermediate];

        if ($prefix_length === $new_length) {
            return $intermediate;
        }

        $new_suffix  = \substr($edge, $prefix_length);
        $new_node_id = _compile_new_node($nodes, $nodes[$intermediate]->segment_offset, $new_suffix);
        $nodes[$intermediate]->children[\ord($new_suffix[0])] = [$new_suffix, $new_node_id];
        return $new_node_id;
    }
}

// Bytes past the last '/' on the path from root to the new node. 0 means the
// node sits on a '/' boundary; non-zero is a mid-segment intermediate, used
// by the matcher to rewind the cursor when falling back to a param transition.
function _compile_new_node(array &$nodes, int $parent_offset = 0, string $edge_from_parent = ''): int
{
    $last_slash = \strrpos($edge_from_parent, '/');
    $offset     = $last_slash === false
        ? $parent_offset + \strlen($edge_from_parent)
        : \strlen($edge_from_parent) - $last_slash - 1;
    $id         = \count($nodes);
    $nodes[$id] = new _Node($offset);
    return $id;
}

// Single DFS that (1) propagates each boundary node's param child down to its
// non-boundary descendants - so partial static matches can fall through to a
// param transition - and (2) emits the placement order for row-displacement
// packing. The param subtree is walked only from its boundary owner so it is
// placed once even though intermediates now share the same pointer.
function _compile_walk(array &$nodes, int $node_id, int $inherited, array &$order, array &$seen): void
{
    $node       = $nodes[$node_id];
    $is_boundary = $node->segment_offset === 0;
    if ($is_boundary) {
        $next = $node->param_child_id;
    } else {
        if ($node->param_child_id < 0 && $inherited >= 0) {
            $node->param_child_id = $inherited;
        }
        $next = $inherited;
    }

    $order[] = $node_id;

    $static_children = [];
    foreach ($node->children as [, $child_id]) {
        $static_children[] = $child_id;
    }
    \sort($static_children);
    foreach ($static_children as $child_id) {
        if (!isset($seen[$child_id])) {
            $seen[$child_id] = true;
            _compile_walk($nodes, $child_id, $next, $order, $seen);
        }
    }

    if ($is_boundary && $node->param_child_id >= 0 && !isset($seen[$node->param_child_id])) {
        $seen[$node->param_child_id] = true;
        _compile_walk($nodes, $node->param_child_id, -1, $order, $seen);
    }
}

function _compile_emit_packed(array $nodes, array $order): array
{
    // Byte 0 is always included so a row's displacement is reserved in
    // used_slots without a separate used_displacements set.
    $occupied_bytes = [];
    foreach ($nodes as $node_id => $node) {
        $bytes   = \array_keys($node->children);
        $bytes[] = 0;
        $occupied_bytes[$node_id] = $bytes;
    }

    // First-fit displacement assignment. Root is fixed at 0; remaining nodes
    // are placed in DFS order so a node and its descendants stay close.
    $displacement = [0 => 0];
    $used_slots   = [];
    foreach ($occupied_bytes[0] as $byte) {
        $used_slots[$byte] = true;
    }
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

    $size        = \max($displacement) + 256;
    $slots       = \array_fill(0, $size, 0);
    $edge_labels = \array_fill(0, $size, null);

    foreach ($nodes as $node_id => $node) {
        $row = $displacement[$node_id];

        foreach ($node->children as $byte => [$edge, $child_id]) {
            $edge_length = \strlen($edge);
            if ($edge_length > Slot::LENGTH_EDGE_MAX) {
                throw new \LengthException("Edge label too long ($edge_length bytes); max " . Slot::LENGTH_EDGE_MAX . '.');
            }
            $index = $row + $byte;
            $slots[$index] = $byte
                | ($edge_length              << Slot::LENGTH_SHIFT)
                | ($displacement[$child_id]  << Slot::CHILD_SHIFT);
            // Single-byte edges are reconstructable from the dispatched
            // byte; matcher never reads edge_labels[$index] for them.
            if ($edge_length !== Slot::LENGTH_SINGLE_BYTE) {
                $edge_labels[$index] = $edge;
            }
        }

        $has_handler   = $node->route_id >= 0;
        $param_node_id = $node->param_child_id;
        if (!$has_handler && $param_node_id < 0) {
            continue;
        }
        $route_id = $has_handler ? $node->route_id : 0;
        if ($route_id > Slot::ROUTE_ID_MASK) {
            throw new \LengthException("Too many routes ($route_id); max " . Slot::ROUTE_ID_MASK . '.');
        }
        if ($node->segment_offset > Slot::SEGMENT_OFFSET_MASK) {
            throw new \LengthException("Segment offset too large ({$node->segment_offset} bytes); max " . Slot::SEGMENT_OFFSET_MASK . '.');
        }
        $slots[$row] = (($param_node_id >= 0 ? Slot::LENGTH_PARAM : Slot::LENGTH_SENTINEL_ONLY) << Slot::LENGTH_SHIFT)
            | (($param_node_id >= 0 ? $displacement[$param_node_id] : 0) << Slot::CHILD_SHIFT)
            | ($has_handler ? Slot::FLAG_TERMINAL : 0)
            | ($route_id             << Slot::ROUTE_ID_SHIFT)
            | ($node->segment_offset << Slot::SEGMENT_OFFSET_SHIFT);
    }

    return [$slots, $edge_labels];
}
