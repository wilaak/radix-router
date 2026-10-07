<?php

use Wilaak\Http\RadixRouter;

class FuzzTest extends RadixRouterTestCase
{
    private const ALPHABET = ['a', 'b', 'c', 'x', '42', '7', 'me', 'new'];
    private const ROUTE_METHODS = ['GET', 'POST', 'PUT'];
    private const REQUEST_METHODS = ['GET', 'GET', 'POST', 'PUT', 'HEAD', 'DELETE'];

    public function testRandomTablesAgreeWithOracle(): void
    {
        foreach ([1, 2, 3, 4, 5] as $seed) {
            $this->runSeed($seed);
        }
    }

    private function runSeed(int $seed): void
    {
        mt_srand($seed);
        for ($t = 0; $t < 60; $t++) {
            [$router, $variants, $patterns] = $this->randomTable();
            for ($q = 0; $q < 150; $q++) {
                $path = $this->randomPath($variants);
                $uri = '/' . implode('/', $path);
                $method = $this->pick(self::REQUEST_METHODS);
                $expected = $this->oracleBest($variants, $path, $method);
                $got = $router->lookup($method, $uri);
                $context = "seed=$seed $method $uri routes=" . implode(', ', $patterns);
                if ($expected === null) {
                    $this->assertSame(404, $got['code'], $context);
                    continue;
                }
                if (isset($expected['allowed'])) {
                    $this->assertSame(405, $got['code'], $context);
                    $this->assertEqualsCanonicalizing($expected['allowed'], $got['allowed_methods'], $context);
                    $this->assertEqualsCanonicalizing($expected['allowed'], $router->methods($uri), $context);
                    continue;
                }
                $this->assertSame(200, $got['code'], $context);
                $this->assertSame($expected['handler'], $got['handler'], $context);
                $this->assertSame($expected['params'], $got['params'], $context);
            }
        }
    }

    private function pick(array $a): mixed
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    private function randomTable(): array
    {
        $router = new RadixRouter();
        $variants = [];
        $patterns = [];
        $n = mt_rand(2, 14);
        for ($r = 0; $r < $n; $r++) {
            $depth = mt_rand(1, 5);
            $segs = [];
            $pn = 0;
            $optStarted = false;
            for ($d = 0; $d < $depth; $d++) {
                $roll = mt_rand(1, 100);
                if ($d === $depth - 1 && $roll <= 15) {
                    $segs[] = ['kind' => 'w', 'name' => 'w', 'min' => mt_rand(0, 1), 'opt' => false];
                    break;
                }
                if ($roll <= 50 && !$optStarted) {
                    $segs[] = ['kind' => 's', 'lit' => $this->pick(self::ALPHABET), 'opt' => false];
                    continue;
                }
                $optStarted = $optStarted || mt_rand(1, 100) <= 20;
                $segs[] = ['kind' => 'p', 'name' => 'p' . $pn++, 'opt' => $optStarted];
            }
            $pattern = '/' . implode('/', array_map(fn($s) => match ($s['kind']) {
                's' => $s['lit'],
                'p' => ':' . $s['name'] . ($s['opt'] ? '?' : ''),
                'w' => ':' . $s['name'] . ($s['min'] ? '+' : '*'),
            }, $segs));
            $method = $this->pick(self::ROUTE_METHODS);
            try {
                $router->add($method, $pattern, "h$r");
            } catch (InvalidArgumentException) {
                continue;
            }
            $patterns[] = "h$r=$method $pattern";
            foreach ($this->expandOptional($segs) as $v) {
                $variants[] = ["h$r", $v, $method];
            }
        }
        return [$router, $variants, $patterns];
    }

    private function randomPath(array $variants): array
    {
        $path = [];
        if ($variants && mt_rand(1, 100) <= 80) {
            [, $segs] = $this->pick($variants);
            foreach ($segs as $s) {
                if ($s['kind'] === 'w') {
                    for ($k = mt_rand($s['min'], 3); $k > 0; $k--) {
                        $path[] = $this->pick(self::ALPHABET);
                    }
                    break;
                }
                $path[] = $s['kind'] === 's' ? $s['lit'] : $this->pick(self::ALPHABET);
            }
            return $path;
        }
        for ($k = mt_rand(1, 5); $k > 0; $k--) {
            $path[] = $this->pick(self::ALPHABET);
        }
        return $path;
    }

    private function expandOptional(array $segs): array
    {
        $required = count($segs);
        foreach ($segs as $i => $s) {
            if ($s['opt']) {
                $required = $i;
                break;
            }
        }
        $out = [];
        for ($len = $required; $len <= count($segs); $len++) {
            $out[] = array_slice($segs, 0, $len);
        }
        return $out;
    }

    private function oracleMatch(array $segs, array $path): ?array
    {
        $params = [];
        foreach ($segs as $i => $s) {
            if ($s['kind'] === 'w') {
                $rest = array_slice($path, $i);
                if ($s['min'] === 1 && $rest === []) {
                    return null;
                }
                $params[$s['name']] = implode('/', $rest);
                return $params;
            }
            if (!isset($path[$i])) {
                return null;
            }
            if ($s['kind'] === 's') {
                if ($path[$i] !== $s['lit']) {
                    return null;
                }
                continue;
            }
            $params[$s['name']] = $path[$i];
        }
        return count($path) === count($segs) ? $params : null;
    }

    private function oracleBest(array $variants, array $path, string $method): ?array
    {
        $best = null;
        $bestKey = null;
        $allowed = [];
        foreach ($variants as [$handler, $segs, $routeMethod]) {
            $params = $this->oracleMatch($segs, $path);
            if ($params === null) {
                continue;
            }
            $allowed[$routeMethod] = true;
            if ($routeMethod === 'GET') {
                $allowed['HEAD'] = true;
            }
            if ($routeMethod !== $method && !($method === 'HEAD' && $routeMethod === 'GET')) {
                continue;
            }
            $kinds = '';
            foreach ($segs as $s) {
                $kinds .= match ($s['kind']) { 's' => 'a', 'p' => 'b', 'w' => 'c' };
            }
            $key = [str_contains($kinds, 'c') ? 1 : 0, $kinds];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = ['handler' => $handler, 'params' => $params];
            }
        }
        if ($best === null && $allowed !== []) {
            return ['allowed' => array_keys($allowed)];
        }
        return $best;
    }
}
