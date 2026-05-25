<?php

declare(strict_types=1);

namespace DartRouter;

if (\PHP_INT_SIZE !== 8) {
    throw new \RuntimeException('DartRouter requires 64-bit PHP; PHP_INT_SIZE is ' . \PHP_INT_SIZE . '.');
}

//
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
// Each row's slot 0 is a sentinel (terminal info, param fallback, segment
// metadata). Slots 1..255 are static edges keyed by the edge's first byte.
// Byte 0 is reserved during D assignment so edges never sit where a
// sentinel could be. Paths are '/'-delimited and contain no NUL, so byte 0
// is never a path byte.
//
// Param ':name' children are not static edges. Each node has at most one.
// It is tried as a fallback when no static edge matches.
//

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
//   +----------------+----------------+--------+----------------+--------+
//   |     63..48     |     47..32     | 31..24 |     23..8      |  7..0  |
//   |    (unused)    |   tail_index   | length |   child_base   | owner  |
//   +----------------+----------------+--------+----------------+--------+
//
// tail_index points into a compact tails[] list; 0 if length == 1 (no tail).
//
// Sentinel slot (owner == 0):
//
//   +--------+----------------+----------------+----------------+--------+
//   | 63..56 |     55..40     |     39..24     |     23..8      |  7..0  |
//   | rewind |     route      |    wildcard    |   param_base   |   0    |
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
//   wildcard       routeId + 1 of a ':name*' greedy capture mounted at this
//                  node, or 0 if none. Captures the rest of the path. The
//                  matcher remembers the most recent in-scope wildcard and
//                  uses it on any later failure.
//   route          routeId + 1 on terminals. 0 means non-terminal.
//   rewind         Bytes past the last '/' on the path to this node. The
//                  matcher subtracts this from the cursor to recover the
//                  current segment's start when falling back to param.
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
//

final class Slot
{
    public const OWNER_MASK            = 0xFF;

    public const CHILD_SHIFT           = 8;
    public const CHILD_MASK            = 0xFFFF;

    public const LENGTH_SHIFT          = 24;
    public const LENGTH_MASK           = 0xFF;

    public const LENGTH_SINGLE_BYTE    = 0x01;
    public const LENGTH_EDGE_MAX       = 0xFF;

    public const TAIL_INDEX_SHIFT      = 32;
    public const TAIL_INDEX_MASK       = 0xFFFF;

    public const WILDCARD_SHIFT        = 24;
    public const WILDCARD_MASK         = 0xFFFF;

    public const ROUTE_ID_SHIFT        = 40;
    public const ROUTE_ID_MASK         = 0xFFFF;

    public const REWIND_SHIFT          = 56;
    public const REWIND_MASK           = 0xFF;
}

final class Router
{
    /** @var array<int, int> */
    public array $_slots = [];
    /** @var string[] dense tail strings, indexed by the slot's tail_index field. */
    public array $_tails = [];
    /** @var string[] */
    public array $_patterns = [];
    /** @var array<int, mixed> indexed by routeId (parallel to $_patterns) */
    public array $_handlers = [];
    /** @var array<string, mixed> exact-match fast path for patterns with no ':' param. */
    public array $_static = [];
}

final class _Node
{
    /** @var array<int, array{string, int}> [byte] => [edgeString, childNodeId] */
    public array $_children = [];
    /** Single-segment ':name' fallback child id, or -1. */
    public int $_paramChildId = -1;
    /** routeId at terminal nodes, or -1; >= 0 also means "this node is terminal". */
    public int $_routeId = -1;
    /** routeId of a ':name*' wildcard mounted at this node, or -1. */
    public int $_wildcardRouteId = -1;

    public function __construct(
        public int $_rewind = 0,
    ) {}
}

function lookup(Router $router, string $method, string $path): array
{
    $routes     = $router->_static[$path] ?? null;
    $paramCount = 0;
    if ($routes !== null) {
        goto DISPATCH;
    }

    $slots  = $router->_slots;
    $tails  = $router->_tails;
    $length = \strlen($path);

    $nodeBase = 0;
    $cursor   = 1;

    $wildcardRoute      = 0;
    $wildcardStart      = 0;
    $wildcardParamCount = 0;

    while ($cursor < $length) {
        $sentinel = (int) $slots[$nodeBase];

        $wildcardField = ($sentinel >> Slot::WILDCARD_SHIFT) & Slot::WILDCARD_MASK;
        if ($wildcardField !== 0) {
            $wildcardRoute      = $wildcardField;
            $wildcardStart      = $cursor;
            $wildcardParamCount = $paramCount;
        }

        $byte     = \ord($path[$cursor]);
        $edgeSlot = (int) $slots[$nodeBase + $byte];

        if (($edgeSlot & Slot::OWNER_MASK) === $byte) {
            $edgeLength = ($edgeSlot >> Slot::LENGTH_SHIFT) & Slot::LENGTH_MASK;
            $tailIndex  = ($edgeSlot >> Slot::TAIL_INDEX_SHIFT) & Slot::TAIL_INDEX_MASK;
            if (
                $edgeLength === Slot::LENGTH_SINGLE_BYTE
                || \substr_compare($path, $tails[$tailIndex], $cursor + 1, $edgeLength - 1) === 0
            ) {
                $nodeBase = ($edgeSlot >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
                $cursor  += $edgeLength;
                continue;
            }
        }

        $paramBase = ($sentinel >> Slot::CHILD_SHIFT) & Slot::CHILD_MASK;
        if ($paramBase !== 0) {
            $segmentEnd = \strpos($path, '/', $cursor);
            if ($segmentEnd === false) {
                $segmentEnd = $length;
            }
            if ($segmentEnd !== $cursor) {
                $segmentStart        = $cursor - (($sentinel >> Slot::REWIND_SHIFT) & Slot::REWIND_MASK);
                $params[$paramCount] = \substr($path, $segmentStart, $segmentEnd - $segmentStart);
                $paramCount++;
                $nodeBase = $paramBase;
                $cursor   = $segmentEnd + 1;
                continue;
            }
        }

        if ($wildcardRoute !== 0) {
            $params[$wildcardParamCount] = \substr($path, $wildcardStart);
            $routes     = $router->_handlers[$wildcardRoute - 1];
            $paramCount = $wildcardParamCount + 1;
            goto DISPATCH;
        }
        return ['code' => 404];
    }

    $finalSentinel = (int) $slots[$nodeBase];
    $route = ($finalSentinel >> Slot::ROUTE_ID_SHIFT) & Slot::ROUTE_ID_MASK;
    if ($route !== 0) {
        $routes = $router->_handlers[$route - 1];
        goto DISPATCH;
    }

    $finalWildcard = ($finalSentinel >> Slot::WILDCARD_SHIFT) & Slot::WILDCARD_MASK;
    if ($finalWildcard !== 0) {
        $params[$paramCount] = '';
        $routes              = $router->_handlers[$finalWildcard - 1];
        $paramCount++;
        goto DISPATCH;
    }

    if ($wildcardRoute !== 0) {
        $params[$wildcardParamCount] = \substr($path, $wildcardStart);
        $routes     = $router->_handlers[$wildcardRoute - 1];
        $paramCount = $wildcardParamCount + 1;
        goto DISPATCH;
    }
    return ['code' => 404];

    DISPATCH:
    if ($method !== '*') {
        $result = $routes[$method] ?? null;
        if ($result === null && $method === 'HEAD') {
            $result = $routes['GET'] ?? null;
        }
        $result ??= $routes['*'] ?? null;

        if ($result !== null) {
            if ($paramCount !== 0) {
                $result['params'] = \array_combine($result['params'], $params);
            }
            return $result;
        }
    }

    $allowedMethods = \array_keys($routes);
    if (isset($routes['GET']) && !isset($routes['HEAD'])) {
        $allowedMethods[] = 'HEAD';
    }
    return ['code' => 405, 'allowed_methods' => $allowedMethods, '_routes' => $routes];
}


function add(Router $router, string|array $methods, string $pattern, mixed $handler = null): void
{
    if (!\str_starts_with($pattern, '/')) {
        $pattern = "/{$pattern}";
    }

    if (\is_array($methods)) {
        if ($methods === []) {
            throw new \InvalidArgumentException("Invalid HTTP method: empty array for pattern '{$pattern}'");
        }
        foreach ($methods as $m) {
            add($router, $m, $pattern, $handler);
        }
        return;
    }

    $method = \strtoupper($methods);

    // Extract param names so lookup can return them keyed without re-parsing.
    $params = [];
    if ($pattern !== '/') {
        foreach (\explode('/', \substr($pattern, 1)) as $segment) {
            if ($segment === '' || $segment[0] !== ':') {
                continue;
            }
            $name = \substr($segment, 1);
            if (\str_ends_with($name, '*')) {
                $name = \substr($name, 0, -1);
            }
            $params[] = $name;
        }
    }

    // Pre-build the lookup result so dispatch is a refcount bump.
    $struct = [
        'code'    => 200,
        'handler' => $handler,
        'params'  => $params,
        'pattern' => $pattern,
    ];

    // Reuse the same trie slot for this pattern across methods so the
    // method-map lives on a single routeId.
    $existing = \array_search($pattern, $router->_patterns, true);
    if ($existing !== false) {
        if (isset($router->_handlers[$existing][$method])) {
            $conflict = $router->_handlers[$existing][$method]['pattern'];
            throw new \InvalidArgumentException(
                "Route conflict: [{$method}] '{$pattern}'"
                    . ($conflict !== $pattern ? " (conflicts with '{$conflict}')" : '')
            );
        }
        $router->_handlers[$existing][$method] = $struct;
        return;
    }

    $router->_patterns[] = $pattern;
    $router->_handlers[] = [$method => $struct];
}

function compile(Router $router): void
{
    /** @var _Node[] $nodes */
    $nodes = [new _Node()];
    $router->_static = [];
    foreach ($router->_patterns as $routeId => $pattern) {
        if (!\str_contains($pattern, '/:')) {
            $router->_static[$pattern] = $router->_handlers[$routeId];
            continue;
        }
        _compileInsert($nodes, $pattern, $routeId);
    }
    $order        = _compileWalk($nodes);
    $displacement = _compileAssignDisplacements($nodes, $order);
    [$router->_slots, $router->_tails] = _compileEmitSlots($nodes, $displacement);
}

//
// Compile internals
//

function _compileInsert(array &$nodes, string $pattern, int $routeId): void
{
    $segments  = $pattern === '' || $pattern === '/' ? [] : \explode('/', \substr($pattern, 1));
    $lastIndex = \count($segments) - 1;

    $nodeId       = 0;
    $staticBuffer = '';
    foreach ($segments as $i => $segment) {
        $isWildcard = isset($segment[0]) && $segment[0] === ':' && \substr($segment, -1) === '*';
        if ($isWildcard) {
            if ($i !== $lastIndex) {
                throw new \DomainException("Wildcard segment ':name*' must be the last segment: $pattern");
            }
            if ($staticBuffer !== '') {
                $nodeId = _compileInsertStatic($nodes, $nodeId, $staticBuffer);
                $staticBuffer = '';
            }
            if ($nodes[$nodeId]->_wildcardRouteId >= 0) {
                throw new \DomainException("Conflicting wildcard registration at: $pattern");
            }
            $nodes[$nodeId]->_wildcardRouteId = $routeId;
            return;
        }
        if (\str_starts_with($segment, ':')) {
            if ($staticBuffer !== '') {
                $nodeId = _compileInsertStatic($nodes, $nodeId, $staticBuffer);
                $staticBuffer = '';
            }
            if ($nodes[$nodeId]->_paramChildId < 0) {
                $nodes[$nodeId]->_paramChildId = _compileNewNode($nodes);
            }
            $nodeId = $nodes[$nodeId]->_paramChildId;
        } else {
            $staticBuffer .= $segment . '/';
        }
    }
    if ($staticBuffer !== '') {
        $nodeId = _compileInsertStatic($nodes, $nodeId, \substr($staticBuffer, 0, -1));
    }
    $nodes[$nodeId]->_routeId = $routeId;
}

function _compileInsertStatic(array &$nodes, int $nodeId, string $edge): int
{
    while (true) {
        $firstByte = \ord($edge[0]);
        if (!isset($nodes[$nodeId]->_children[$firstByte])) {
            $newNodeId = _compileNewNode($nodes, $nodes[$nodeId]->_rewind, $edge);
            $nodes[$nodeId]->_children[$firstByte] = [$edge, $newNodeId];
            return $newNodeId;
        }

        [$existingEdge, $childNodeId] = $nodes[$nodeId]->_children[$firstByte];
        $newLength      = \strlen($edge);
        $existingLength = \strlen($existingEdge);
        $compareLength  = $newLength < $existingLength ? $newLength : $existingLength;
        $prefixLength   = 0;
        while ($prefixLength < $compareLength && $edge[$prefixLength] === $existingEdge[$prefixLength]) {
            $prefixLength++;
        }

        if ($prefixLength === $existingLength) {
            if ($prefixLength === $newLength) {
                return $childNodeId;
            }
            $nodeId = $childNodeId;
            $edge   = \substr($edge, $prefixLength);
            continue;
        }

        $consumedPrefix = \substr($existingEdge, 0, $prefixLength);
        $intermediate   = _compileNewNode($nodes, $nodes[$nodeId]->_rewind, $consumedPrefix);
        $existingSuffix = \substr($existingEdge, $prefixLength);
        $nodes[$intermediate]->_children[\ord($existingSuffix[0])] = [$existingSuffix, $childNodeId];
        $nodes[$nodeId]->_children[$firstByte] = [$consumedPrefix, $intermediate];

        if ($prefixLength === $newLength) {
            return $intermediate;
        }

        $newSuffix = \substr($edge, $prefixLength);
        $newNodeId = _compileNewNode($nodes, $nodes[$intermediate]->_rewind, $newSuffix);
        $nodes[$intermediate]->_children[\ord($newSuffix[0])] = [$newSuffix, $newNodeId];
        return $newNodeId;
    }
}

// Bytes past the last '/' on the path from root to the new node. 0 means the
// node sits on a '/' boundary; non-zero is a mid-segment intermediate, used
// by the matcher to rewind the cursor when falling back to a param transition.
function _compileNewNode(array &$nodes, int $parentRewind = 0, string $edgeFromParent = ''): int
{
    $lastSlash = \strrpos($edgeFromParent, '/');
    $rewind    = $lastSlash === false
        ? $parentRewind + \strlen($edgeFromParent)
        : \strlen($edgeFromParent) - $lastSlash - 1;
    $id        = \count($nodes);
    $nodes[$id] = new _Node($rewind);
    return $id;
}

// Iterative DFS that (1) propagates each boundary node's param child down to
// its non-boundary descendants - so partial static matches can fall through
// to a param transition - and (2) emits the placement order for row-
// displacement packing. The param subtree is walked only from its boundary
// owner so it is placed once even though intermediates share the same pointer.
//
// Stack frames carry [nodeId, inheritedParam]. Children are pushed in reverse
// desired-pop-order so preorder matches the recursive form: self, sorted
// static subtrees, then own param subtree.
function _compileWalk(array &$nodes): array
{
    $order = [];
    $seen  = [0 => true];
    $stack = [[0, -1]];
    while ($stack !== []) {
        [$nodeId, $inheritedParam] = \array_pop($stack);
        $node       = $nodes[$nodeId];
        $isBoundary = $node->_rewind === 0;
        if ($isBoundary) {
            $nextParam = $node->_paramChildId;
        } else {
            if ($node->_paramChildId < 0 && $inheritedParam >= 0) {
                $node->_paramChildId = $inheritedParam;
            }
            $nextParam = $inheritedParam;
        }

        $order[] = $nodeId;

        // Own subtree walks start fresh with no inherited fallback.
        if ($isBoundary && $node->_paramChildId >= 0 && !isset($seen[$node->_paramChildId])) {
            $seen[$node->_paramChildId] = true;
            $stack[] = [$node->_paramChildId, -1];
        }

        $staticChildren = [];
        foreach ($node->_children as [, $childId]) {
            $staticChildren[] = $childId;
        }
        \sort($staticChildren);
        // Reverse so the smallest sorted child pops first.
        for ($i = \count($staticChildren) - 1; $i >= 0; $i--) {
            $childId = $staticChildren[$i];
            if (!isset($seen[$childId])) {
                $seen[$childId] = true;
                $stack[] = [$childId, $nextParam];
            }
        }
    }
    return $order;
}

// First-fit row-displacement assignment. Root is fixed at D=0; remaining
// nodes are placed in DFS order so a node and its descendants stay close
// in the flat slots[] array. Byte 0 is always part of a row's occupied
// set so the row's displacement itself is reserved without tracking a
// separate usedDisplacements set.
function _compileAssignDisplacements(array $nodes, array $order): array
{
    $occupiedBytes = [];
    foreach ($nodes as $nodeId => $node) {
        $bytes   = \array_keys($node->_children);
        $bytes[] = 0;
        $occupiedBytes[$nodeId] = $bytes;
    }

    $displacement = [0 => 0];
    $usedSlots    = [];
    foreach ($occupiedBytes[0] as $byte) {
        $usedSlots[$byte] = true;
    }
    $frontier = 1;
    foreach ($order as $nodeId) {
        if ($nodeId === 0) {
            continue;
        }
        $bytes = $occupiedBytes[$nodeId];
        while (isset($usedSlots[$frontier])) {
            $frontier++;
        }
        $candidate = $frontier;
        while (true) {
            $fits = true;
            foreach ($bytes as $byte) {
                if (isset($usedSlots[$candidate + $byte])) {
                    $fits = false;
                    break;
                }
            }
            if ($fits) {
                $displacement[$nodeId] = $candidate;
                foreach ($bytes as $byte) {
                    $usedSlots[$candidate + $byte] = true;
                }
                break;
            }
            do {
                $candidate++;
            } while (isset($usedSlots[$candidate]));
        }
    }

    return $displacement;
}

// Walks nodes once and writes one row per node into the flat slots[] /
// tails[] buffers using the displacement table. Edge slots go at
// nodeBase+byte; the sentinel at nodeBase packs route/param/rewind.
function _compileEmitSlots(array $nodes, array $displacement): array
{
    $size  = \max($displacement) + 256;
    $slots = \array_fill(0, $size, 0);
    // Index 0 reserved so single-byte edges (tail_index field == 0) never
    // collide with a real tail entry.
    $tails = [''];

    foreach ($nodes as $nodeId => $node) {
        $nodeBase = $displacement[$nodeId];

        foreach ($node->_children as $byte => [$edge, $childId]) {
            $edgeLength = \strlen($edge);
            if ($edgeLength > Slot::LENGTH_EDGE_MAX) {
                throw new \LengthException("Edge label too long ($edgeLength bytes); max " . Slot::LENGTH_EDGE_MAX . '.');
            }
            $tailIndex = 0;
            if ($edgeLength !== Slot::LENGTH_SINGLE_BYTE) {
                $tailIndex = \count($tails);
                if ($tailIndex > Slot::TAIL_INDEX_MASK) {
                    throw new \LengthException("Too many multi-byte edges ($tailIndex); max " . Slot::TAIL_INDEX_MASK . '.');
                }
                $tails[] = \substr($edge, 1);
            }
            $slots[$nodeBase + $byte] = $byte
                | ($displacement[$childId] << Slot::CHILD_SHIFT)
                | ($edgeLength             << Slot::LENGTH_SHIFT)
                | ($tailIndex              << Slot::TAIL_INDEX_SHIFT);
        }

        $hasHandler  = $node->_routeId >= 0;
        $hasWildcard = $node->_wildcardRouteId >= 0;
        $paramNodeId = $node->_paramChildId;
        if (!$hasHandler && !$hasWildcard && $paramNodeId < 0) {
            continue;
        }
        $route = $hasHandler ? $node->_routeId + 1 : 0;
        if ($route > Slot::ROUTE_ID_MASK) {
            throw new \LengthException("Too many routes ({$node->_routeId}); max " . (Slot::ROUTE_ID_MASK - 1) . '.');
        }
        $wildcard = $hasWildcard ? $node->_wildcardRouteId + 1 : 0;
        if ($wildcard > Slot::WILDCARD_MASK) {
            throw new \LengthException("Too many routes ({$node->_wildcardRouteId}); max " . (Slot::WILDCARD_MASK - 1) . '.');
        }
        if ($node->_rewind > Slot::REWIND_MASK) {
            throw new \LengthException("Rewind too large ({$node->_rewind} bytes); max " . Slot::REWIND_MASK . '.');
        }
        $paramBase = $paramNodeId >= 0 ? $displacement[$paramNodeId] : 0;
        $slots[$nodeBase] = ($paramBase << Slot::CHILD_SHIFT)
            | ($wildcard      << Slot::WILDCARD_SHIFT)
            | ($route         << Slot::ROUTE_ID_SHIFT)
            | ($node->_rewind << Slot::REWIND_SHIFT);
    }

    return [$slots, $tails];
}
