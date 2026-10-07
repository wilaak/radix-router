<?php

use Wilaak\Http\RadixRouter;

class DeepBacktrackingTest extends RadixRouterTestCase
{
    private function chain(int $n): string
    {
        return $n ? '/' . implode('/', array_fill(0, $n, 'a')) : '';
    }

    private function table(int $depth): RadixRouter
    {
        $router = new RadixRouter();
        for ($k = 0; $k < $depth; $k++) {
            $router->add('GET', $this->chain($k) . '/:p' . $this->chain($depth - $k - 1) . "/x$k", "param_at_$k");
        }
        return $router;
    }

    public function testBacktracksThroughAllMaskedForks(): void
    {
        $router = $this->table(PHP_INT_SIZE * 4 + 1);
        foreach ([PHP_INT_SIZE * 4, PHP_INT_SIZE * 2, 0] as $k) {
            $info = $router->lookup('GET', $this->chain(PHP_INT_SIZE * 4 + 1) . "/x$k");
            $this->assertSame("param_at_$k", $info['handler']);
        }
        $this->assertSame(404, $router->lookup('GET', $this->chain(PHP_INT_SIZE * 4 + 1) . '/nope')['code']);
    }

    public function testTerminatesBeyondMaskCapacity(): void
    {
        $router = $this->table(100);
        $this->assertSame(404, $router->lookup('GET', $this->chain(100) . '/nope')['code']);
        $info = $router->lookup('GET', $this->chain(100) . '/x0');
        $this->assertSame('param_at_0', $info['handler']);
        $info = $router->lookup('GET', $this->chain(100) . '/x15');
        $this->assertSame('param_at_15', $info['handler']);
    }
}
