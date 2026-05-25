<?php

use Wilaak\Http\RadixRouter;

class PrecedenceTest extends RadixRouterTestCase
{
    public function testStaticBeatsParameterBeatsWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/priority/static', 'static_handler');
        $router->add('GET', '/priority/:param',  'param_handler');
        $router->add('GET', '/priority/:rest*',  'wildcard_handler');

        $info = $router->lookup('GET', '/priority/static');
        $this->assertEquals('static_handler', $info['handler']);

        $info = $router->lookup('GET', '/priority/foo');
        $this->assertEquals('param_handler', $info['handler']);
        $this->assertEquals(['param' => 'foo'], $info['params']);

        $info = $router->lookup('GET', '/priority/foo/bar/baz');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => 'foo/bar/baz'], $info['params']);
    }

    public function testStaticNodeFallbackToParameterNode()
    {
        $router = new RadixRouter();
        $router->add('GET', '/test/:test', function ($test) {
            return "Second: /test/:test, param = $test";
        });
        $router->add('GET', '/:test', function ($test) {
            return "First: /:test, param = $test";
        });

        $info = $router->lookup('GET', '/test');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('First: /:test, param = test', $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/test/foo');
        $this->assertEquals('Second: /test/:test, param = foo', $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/foo');
        $this->assertEquals('First: /:test, param = foo', $info['handler'](...$info['params']));
    }

    public function testStaticNodeFallbackToWildcardSibling()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:foo', function ($foo) {
            return 'Foo';
        });
        $router->add('GET', '/bar/:foo*', function ($foo) {
            return 'Bar';
        });

        $info = $router->lookup('GET', '/bar');
        $this->assertEquals('Foo', $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/bar/foo');
        $this->assertEquals('Bar', $info['handler'](...$info['params']));
    }

    // Fallback chain after a partial walk:
    //   1. terminal node's routes
    //   2. parent's param-sibling (lastStaticNode[PARAM])
    //   3. terminal node's wildcard child
    //   4. saved ancestor wildcard
    // This pins step 2 winning over step 3.
    public function testParamSiblingFallbackBeatsTerminalWildcardChild()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:x',         'param_sibling');
        $router->add('GET', '/foo/:rest*', 'terminal_wildcard');

        $info = $router->lookup('GET', '/foo');
        $this->assertSame('param_sibling', $info['handler']);
        $this->assertSame(['x' => 'foo'], $info['params']);
    }

    // Once a more-specific route is found, a method mismatch produces a
    // 405 from THAT route. The router does not keep looking for a less
    // specific route that would have accepted the method.
    public function testMethodMismatchAtSpecificRouteDoesNotFallThroughToLessSpecific()
    {
        $router = new RadixRouter();
        $router->add('POST', '/foo',  'specific');
        $router->add('GET',  '/:x',   'catch_all');

        $info = $router->lookup('GET', '/foo');
        $this->assertSame(405, $info['code']);
        $this->assertSame(['POST'], $info['allowed_methods']);
    }

    // The HEAD-to-GET fallback runs inside DISPATCH, which is reached
    // from every fallback branch including the param-sibling one.
    public function testHeadFallsBackToGetThroughParamSiblingFallback()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:x',        'catch_all');
        $router->add('GET', '/foo/:bar',  'deeper');

        $info = $router->lookup('HEAD', '/foo');
        $this->assertSame('catch_all', $info['handler']);
        $this->assertSame(['x' => 'foo'], $info['params']);
    }
}
