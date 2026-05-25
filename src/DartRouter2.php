<?php

declare(strict_types=1);

namespace DartRouter;

if (\PHP_INT_SIZE !== 8) {
    throw new \RuntimeException('DartRouter requires 64-bit PHP; PHP_INT_SIZE is ' . \PHP_INT_SIZE . '.');
}

// DISPLACEMENT ARRAY RADIX TRIE (DART)
//
// A radix trie stored as a flat slots[] array instead of node objects with
// child pointers. Each node has a displacement D; its row lives at
// slots[D + b] for path byte b. Lookup is base + byte arithmetic.
//
// tails[] is a parallel array of strings, indexed the same way as slots[].
// It holds the bytes past the owner byte for multi-byte edges. PHP has no
// way to compare ranges of a raw byte buffer, so each tail must live as
// its own heap-allocated string for substr_compare to work against it.
//
// Each row's slot 0 is a sentinel (terminal info, param/wildcard fallback,
// segment metadata). Slots 1..255 are static edges keyed by the edge's
// first byte. Byte 0 is reserved during D assignment so edges never sit
// where a sentinel could be. Paths are '/'-delimited and contain no NUL,
// so byte 0 is never a path byte.
//
// Param ':name' and wildcard ':name*' children are not static edges.
// Each node has at most one of each. They are tried as a fallback when no
// static edge matches.
//
// SLOT BIT LAYOUT (64-bit, byte-aligned)
//
// Edges and sentinels share the same 64-bit word. The owner byte (bits
// 0..7) tells them apart: non-zero on edges, zero on sentinels. The owner
// byte also detects foreign-row aliases. If owner != path byte, this slot
// belongs to another node's row.
//
// Edge slot (owner != 0):
//
//   +--------------------------------+--------+----------------+--------+
//   |            63..32              | 31..24 |     23..8      |  7..0  |
//   |            (unused)            | length |   child_base   | owner  |
//   +--------------------------------+--------+----------------+--------+
//
// Sentinel slot (owner == 0):
//
//   +--------+----------------+----------------+----------------+--------+
//   | 63..56 |     55..40     |     39..24     |     23..8      |  7..0  |
//   | seg_of |     route      |  wildcard_base |   param_base   |   0    |
//   +--------+----------------+----------------+----------------+--------+
//
// child_base / param_base share bits 8..23 so the same shift/mask reads both.
// length == 1: edge is owner byte alone (no tails[] entry). length > 1: tail
// bytes live in tails[index].
//
// FIELD SEMANTICS
//
//   param_base     D of the ':name' fallback child, or 0 if none. Root is
//                  never a child, so 0 always means "no fallback".
//   wildcard_base  D of the ':name*' fallback child, or 0 if none. Wildcards
//                  are always terminal. The matcher remembers the last
//                  wildcard ancestor it saw. On failure it rewinds to that
//                  ancestor's segment start and swallows the rest of the path.
//   route          route_id + 1 on terminals. 0 means non-terminal.
//   seg_of         Bytes past the last '/' on the path to this node. Lets
//                  the matcher rewind the cursor to the current segment start
//                  when falling back to param or wildcard.
//
// DISPLACEMENT EXAMPLE
//
// Routes /user, /users, /posts. Bytes 'p'=112, 's'=115, 'u'=117:
//
//   index:    0    1    2    3   ...  112  ...  116  117  ...
//             |    |    |    |        |         |    |
//             |    |    |    |        |         |    +-- edge "user"  -> D=1
//             |    |    |    |        |         +------ edge "s"      -> D=2
//             |    |    |    |        +---------------- edge "posts"  -> D=3
//             |    |    |    +-- sentinel n3 (terminal /posts)
//             |    |    +------ sentinel n2 (terminal /users)
//             |    +---------- sentinel n1 (terminal /user)
//             +--------------- sentinel root
//
//   D(root)=0  occupies {0, 112, 117}
//   D(n1)=1    occupies {1, 116 = 1 + 's'}    overlaps root's row
//   D(n2)=2    occupies {2}
//   D(n3)=3    occupies {3}


final readonly class Slot
{
    public const OWNER_MASK            = 0xFF;

    public const CHILD_SHIFT           = 8;
    public const CHILD_MASK            = 0xFFFF;

    public const WILDCARD_BASE_SHIFT   = 24;
    public const WILDCARD_BASE_MASK    = 0xFFFF;

    public const LENGTH_SHIFT          = 24;
    public const LENGTH_MASK           = 0xFF;

    public const LENGTH_SINGLE_BYTE    = 0x01;
    public const LENGTH_EDGE_MAX       = 0xFF;

    public const ROUTE_ID_SHIFT        = 40;
    public const ROUTE_ID_MASK         = 0xFFFF;

    public const SEGMENT_OFFSET_SHIFT  = 56;
    public const SEGMENT_OFFSET_MASK   = 0xFF;
}

final class Router
{
    /** @var array<int, int> */
    public array $slots = [];
    /** @var array<int, ?string> */
    public array $tails = [];
    /** @var string[] */
    public array $patterns = [];
    /** @var array<int, mixed> indexed by route_id (parallel to $patterns) */
    public array $handlers = [];
    /** @var array<string, int> normalized static path => route_id */
    public array $static_routes = [];
}

final class _Node
{
    /** @var array<int, array{string, int}> [byte] => [edge_string, child_node_id] */
    public array $children = [];
    /** single-segment ':name' fallback child id, or -1 */
    public int $param_child_id = -1;
    /** catch-all ':name*' fallback child id, or -1; always terminal */
    public int $wildcard_child_id = -1;
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
    $tails    = $router->tails;
    $handlers = $router->handlers;

    $length = \strlen($path);
    if ($length > 1 && $path[$length - 1] === '/') {
        $length--;
        $key = \substr($path, 0, $length);
    } else {
        $key = $path;
    }
    if (isset($router->static_routes[$key])) {
        return [$handlers[$router->static_routes[$key]], null];
    }
    $base   = 0;
    $cursor = 1;
    $params = null;

    // Stickiest wildcard ancestor seen during traversal.
    $wild_base          = 0;
    $wild_segment_start = 0;
    $wild_params        = [];

    while ($cursor < $length) {
        $base_sentinel = (int) $slots[$base];
        $current_wild  = ($base_sentinel >> Slot::WILDCARD_BASE_SHIFT) & Slot::WILDCARD_BASE_MASK;
        if ($current_wild !== 0) {
            $wild_base          = $current_wild;
            $wild_segment_start = $cursor - (($base_sentinel >> Slot::SEGMENT_OFFSET_SHIFT) & Slot::SEGMENT_OFFSET_MASK);
            $wild_params = $params;
        }

        $byte  = \ord($path[$cursor]); // This shit is slow as fuck Zend? TODO: make faster in php-src
        $index = $base + $byte;

        // Pro top: Type casting for inference on untyped arrays, great for JIT SSA
        $slot  = (int) $slots[$index];

        if (($slot & Slot::OWNER_MASK) === $byte) {
            $edge_length = ($slot >> Slot::LENGTH_SHIFT) & Slot::LENGTH_MASK;
            if (
                $edge_length === Slot::LENGTH_SINGLE_BYTE
                || \substr_compare($path, $tails[$index], $cursor + 1, $edge_length - 1) === 0
            ) {
                $base   = ($slot >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
                $cursor = $cursor + $edge_length;
                continue;
            }
        }

        $param_base = ($base_sentinel >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
        if ($param_base !== 0) {
            $end = \strpos($path, '/', $cursor);
            if ($end === false || $end >= $length) {
                $end = $length;
            }
            if ($end !== $cursor) {
                $segment_start  = $cursor - (($base_sentinel >> Slot::SEGMENT_OFFSET_SHIFT) & Slot::SEGMENT_OFFSET_MASK);
                $params[]       = \substr($path, $segment_start, $end - $segment_start);
                $base           = $param_base;
                $cursor         = $end + 1;
                continue;
            }
        }

        if ($wild_base !== 0) {
            $route_field = ((int) $slots[$wild_base] >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
            if ($route_field !== 0) {
                $wild_params[] = \substr($path, $wild_segment_start);
                return [$handlers[$route_field - 1], $wild_params];
            }
        }
        return null;
    }

    $route_field = ((int) $slots[$base] >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
    if ($route_field !== 0) {
        return [$handlers[$route_field - 1], $params];
    }
    if ($wild_base !== 0) {
        $route_field = ((int) $slots[$wild_base] >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
        if ($route_field !== 0) {
            $wild_params[] = \substr($path, $wild_segment_start);
            return [$handlers[$route_field - 1], $wild_params];
        }
    }
    return null;
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
    $router->static_routes = [];
    foreach ($router->patterns as $route_id => $pattern) {
        if (!\str_contains($pattern, '/:')) {
            $key = \strlen($pattern) > 1 ? \rtrim($pattern, '/') : $pattern;
            $router->static_routes[$key] = $route_id;
            continue;
        }
        _compile_insert($nodes, $pattern, $route_id);
    }
    $order = [];
    $seen  = [0 => true];
    _compile_walk($nodes, 0, -1, -1, $order, $seen);
    [$router->slots, $router->tails] = _compile_emit_packed($nodes, $order);
}

function _compile_insert(array &$nodes, string $pattern, int $route_id): void
{
    $normalized = \rtrim($pattern, '/');
    $segments   = $normalized === '' ? [] : \explode('/', \substr($normalized, 1));

    $node_id       = 0;
    $static_buffer = '';
    $last_index    = \count($segments) - 1;
    foreach ($segments as $i => $segment) {
        if (\str_starts_with($segment, ':')) {
            $is_wildcard = \substr($segment, -1) === '*';
            if ($is_wildcard && $i !== $last_index) {
                throw new \InvalidArgumentException(
                    "Wildcard parameter ':name*' must be the last segment in pattern: $pattern"
                );
            }
            if ($static_buffer !== '') {
                $node_id = _compile_insert_static($nodes, $node_id, $static_buffer);
                $static_buffer = '';
            }
            if ($is_wildcard) {
                if ($nodes[$node_id]->wildcard_child_id < 0) {
                    $nodes[$node_id]->wildcard_child_id = _compile_new_node($nodes);
                }
                $node_id = $nodes[$node_id]->wildcard_child_id;
            } else {
                if ($nodes[$node_id]->param_child_id < 0) {
                    $nodes[$node_id]->param_child_id = _compile_new_node($nodes);
                }
                $node_id = $nodes[$node_id]->param_child_id;
            }
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

// Single DFS that (1) propagates each boundary node's param + wildcard child
// down to its non-boundary descendants - so partial static matches can fall
// through to a param or wildcard transition - and (2) emits the placement
// order for row-displacement packing. Param/wildcard subtrees are walked only
// from their boundary owner so each is placed once even though intermediates
// share the same pointer.
function _compile_walk(array &$nodes, int $node_id, int $inherited_param, int $inherited_wild, array &$order, array &$seen): void
{
    $node       = $nodes[$node_id];
    $is_boundary = $node->segment_offset === 0;
    if ($is_boundary) {
        $next_param = $node->param_child_id;
        $next_wild  = $node->wildcard_child_id;
    } else {
        if ($node->param_child_id < 0 && $inherited_param >= 0) {
            $node->param_child_id = $inherited_param;
        }
        if ($node->wildcard_child_id < 0 && $inherited_wild >= 0) {
            $node->wildcard_child_id = $inherited_wild;
        }
        $next_param = $inherited_param;
        $next_wild  = $inherited_wild;
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
            _compile_walk($nodes, $child_id, $next_param, $next_wild, $order, $seen);
        }
    }

    if ($is_boundary && $node->param_child_id >= 0 && !isset($seen[$node->param_child_id])) {
        $seen[$node->param_child_id] = true;
        _compile_walk($nodes, $node->param_child_id, -1, -1, $order, $seen);
    }
    if ($is_boundary && $node->wildcard_child_id >= 0 && !isset($seen[$node->wildcard_child_id])) {
        $seen[$node->wildcard_child_id] = true;
        _compile_walk($nodes, $node->wildcard_child_id, -1, -1, $order, $seen);
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
                | ($displacement[$child_id]  << Slot::CHILD_SHIFT)
                | ($edge_length              << Slot::LENGTH_SHIFT);

            if ($edge_length !== Slot::LENGTH_SINGLE_BYTE) {
                $edge_labels[$index] = \substr($edge, 1);
            }
        }

        $has_handler      = $node->route_id >= 0;
        $param_node_id    = $node->param_child_id;
        $wildcard_node_id = $node->wildcard_child_id;
        if (!$has_handler && $param_node_id < 0 && $wildcard_node_id < 0) {
            continue;
        }
        $route_field = $has_handler ? $node->route_id + 1 : 0;
        if ($route_field > Slot::ROUTE_ID_MASK) {
            throw new \LengthException("Too many routes ({$node->route_id}); max " . (Slot::ROUTE_ID_MASK - 1) . '.');
        }
        if ($node->segment_offset > Slot::SEGMENT_OFFSET_MASK) {
            throw new \LengthException("Segment offset too large ({$node->segment_offset} bytes); max " . Slot::SEGMENT_OFFSET_MASK . '.');
        }
        $param_base    = $param_node_id    >= 0 ? $displacement[$param_node_id]    : 0;
        $wildcard_base = $wildcard_node_id >= 0 ? $displacement[$wildcard_node_id] : 0;
        $slots[$row] = ($param_base             << Slot::CHILD_SHIFT)
            | ($wildcard_base           << Slot::WILDCARD_BASE_SHIFT)
            | ($route_field             << Slot::ROUTE_ID_SHIFT)
            | ($node->segment_offset    << Slot::SEGMENT_OFFSET_SHIFT);
    }

    return [$slots, $edge_labels];
}
