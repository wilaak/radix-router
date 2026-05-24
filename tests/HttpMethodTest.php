<?php

use Wilaak\Http\RadixRouter;

class HttpMethodTest extends RadixRouterTestCase
{
    public function testMethodNotAllowedReturns405AcrossAllPatternTypes()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET',  $type['pattern'], 'get_handler');
            $router->add('POST', $type['pattern'], 'post_handler');

            $info = $router->lookup('PUT', $type['lookup']);
            $this->assertEquals(405, $info['code'], $type['desc']);
            $this->assertEqualsCanonicalizing(
                ['GET', 'POST', 'HEAD'],
                $info['allowed_methods'],
                $type['desc']
            );
        }
    }

    public function testRegisteringMultipleMethodsViaArray()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add(['GET', 'POST'], $type['pattern'], 'multi_handler');

            $info1 = $router->lookup('GET',  $type['lookup']);
            $info2 = $router->lookup('POST', $type['lookup']);
            $this->assertEquals(200, $info1['code'], $type['desc']);
            $this->assertEquals(200, $info2['code'], $type['desc']);
            $this->assertEquals('multi_handler', $info1['handler'], $type['desc']);
            $this->assertEquals('multi_handler', $info2['handler'], $type['desc']);
        }
    }

    public function testMethodNamesAreCaseInsensitive()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('get', $type['pattern'], 'handler');
            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc']);
            $this->assertEquals('handler', $info['handler'], $type['desc']);
        }
    }

    public function testHeadFallsBackToGet()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');

            $info = $router->lookup('HEAD', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc']);
            $this->assertEquals('get_handler', $info['handler'], $type['desc']);
            $this->assertEquals($type['params'], $info['params'], $type['desc']);

            $this->assertEqualsCanonicalizing(
                ['GET', 'HEAD'],
                $router->methods($type['lookup']),
                $type['desc']
            );
        }
    }

    public function testExplicitHeadOverridesGetFallback()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET',  $type['pattern'], 'get_handler');
            $router->add('HEAD', $type['pattern'], 'head_handler');

            $info = $router->lookup('HEAD', $type['lookup']);
            $this->assertEquals('head_handler', $info['handler'], $type['desc']);

            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals('get_handler', $info['handler'], $type['desc']);

            $this->assertEqualsCanonicalizing(
                ['GET', 'HEAD'],
                $router->methods($type['lookup']),
                $type['desc']
            );
        }
    }

    // The * method registers a handler that matches any method, but an
    // explicit method still wins when present.
    public function testAnyMethodWildcardFallback()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('*',   $type['pattern'], 'all_methods_handler');
            $router->add('GET', $type['pattern'], 'get_handler');

            $info = $router->lookup('POST', $type['lookup']);
            $this->assertEquals('all_methods_handler', $info['handler'], $type['desc']);
            $this->assertEquals($type['params'], $info['params'], $type['desc']);

            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals('get_handler', $info['handler'], $type['desc']);
            $this->assertEquals($type['params'], $info['params'], $type['desc']);

            $this->assertTrue(
                $router->methods($type['lookup']) == $router->allowedMethods,
                $type['desc'] . ': methods listing'
            );

            $this->assertEquals([
                ['method' => '*',   'pattern' => $type['pattern'], 'handler' => 'all_methods_handler'],
                ['method' => 'GET', 'pattern' => $type['pattern'], 'handler' => 'get_handler'],
            ], $router->list($type['lookup']), $type['desc']);
        }
    }

    // When only * is registered, HEAD must resolve via the * handler
    // (no GET to synthesize from).
    public function testHeadResolvesViaAnyMethodHandlerWhenNoGetRegistered()
    {
        $router = new RadixRouter();
        $router->add('*', '/x', 'all_handler');

        $info = $router->lookup('HEAD', '/x');
        $this->assertSame(200, $info['code']);
        $this->assertSame('all_handler', $info['handler']);
    }

    // HEAD is HTTP's "GET without a body" twin (RFC 9110), so an explicit
    // GET drives HEAD even when a * catch-all is also present. Catch-all
    // handlers typically return bodies (CORS, logging, blanket 405s),
    // which is exactly what HEAD must not do.
    public function testHeadFallsBackToExplicitGetEvenWhenAnyMethodRegistered()
    {
        $router = new RadixRouter();
        $router->add('*',   '/x', 'all_handler');
        $router->add('GET', '/x', 'get_handler');

        $this->assertSame('get_handler', $router->lookup('GET',  '/x')['handler']);
        $this->assertSame('get_handler', $router->lookup('HEAD', '/x')['handler']);

        // Other methods still resolve via the * catch-all.
        $this->assertSame('all_handler', $router->lookup('POST',   '/x')['handler']);
        $this->assertSame('all_handler', $router->lookup('DELETE', '/x')['handler']);
    }

    // Lookup method names are case sensitive even though add() uppercases
    // them. Registering 'GET' and looking up 'get' is not a match. This
    // pins down current behavior so it does not change silently. If
    // symmetry is wanted, fix the router rather than this test.
    public function testLookupMethodNameIsCaseSensitive()
    {
        $router = new RadixRouter();
        $router->add('GET', '/x', 'h');

        $this->assertSame(200, $router->lookup('GET', '/x')['code']);
        $this->assertSame(405, $router->lookup('get', '/x')['code']);
    }
}
