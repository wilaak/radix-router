<?php

use Wilaak\Http\RadixRouter;

/**
 * Pins down the shape of the array returned by lookup(). Verifies that
 * the keys (code, handler, params, pattern) and their types are stable
 * across every pattern type and across direct and HEAD-fallback matches.
 */
class LookupResultTest extends RadixRouterTestCase
{
    public function testLookupResultShapeAcrossPatternTypes()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');

            $expected = [
                'code'    => 200,
                'handler' => 'get_handler',
                'params'  => $type['params'],
                'pattern' => $type['pattern'],
            ];

            $this->assertSame(
                $expected,
                $router->lookup('GET', $type['lookup']),
                $type['desc'] . ': GET shape'
            );

            // HEAD fallback reuses the GET entry and must keep the shape.
            $this->assertSame(
                $expected,
                $router->lookup('HEAD', $type['lookup']),
                $type['desc'] . ': HEAD fallback shape'
            );

            // Explicit HEAD wins but the shape is unchanged.
            $router->add('HEAD', $type['pattern'], 'head_handler');
            $this->assertSame(
                [
                    'code'    => 200,
                    'handler' => 'head_handler',
                    'params'  => $type['params'],
                    'pattern' => $type['pattern'],
                ],
                $router->lookup('HEAD', $type['lookup']),
                $type['desc'] . ': explicit HEAD shape'
            );
        }
    }
}
