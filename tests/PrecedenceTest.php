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

    // If matching a static node finds no terminal handler at the requested
    // depth, the router must fall back to a sibling parameter node at the
    // same level rather than giving up.
    public function testStaticNodeFallbackToParameterNode()
    {
        $router = new RadixRouter();
        $router->add('GET', '/test/:test', function ($test) {
            return "Second: /test/:test, param = $test";
        });
        $router->add('GET', '/:test', function ($test) {
            return "First: /:test, param = $test";
        });

        // /test traverses to the static /test node but its only child is
        // the required :test param that needs a non-empty segment. Lookup
        // must fall back to the sibling /:test parameter node.
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
}
