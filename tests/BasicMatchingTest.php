<?php

use Wilaak\Http\RadixRouter;

class BasicMatchingTest extends RadixRouterTestCase
{
    public function testBasicUsage()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:world?', function ($world = 'World') {
            return "Hello, $world!";
        });

        $info1 = $router->lookup('GET', '/');
        $info2 = $router->lookup('GET', '/Earth');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('Hello, World!', $info1['handler'](...$info1['params']));

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('Hello, Earth!', $info2['handler'](...$info2['params']));
    }

    public function testRequiredParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/user/settings', 'static_handler');
        $router->add('GET', '/user/:user', 'param_handler');

        $info1 = $router->lookup('GET', '/user');
        $info2 = $router->lookup('GET', '/user/settings');
        $info3 = $router->lookup('GET', '/user/gordon');

        $this->assertEquals(404, $info1['code']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('static_handler', $info2['handler']);
        $this->assertEquals(200, $info3['code']);
        $this->assertEquals('param_handler', $info3['handler']);
        $this->assertEquals(['user' => 'gordon'], $info3['params']);
    }

    public function testParameterAndStaticConflict()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/bar', 'static_handler');
        $router->add('GET', '/foo/:param', 'param_handler');
        $info1 = $router->lookup('GET', '/foo/bar');
        $info2 = $router->lookup('GET', '/foo/baz');
        $this->assertEquals('static_handler', $info1['handler']);
        $this->assertEquals('param_handler', $info2['handler']);
        $this->assertEquals(['param' => 'baz'], $info2['params']);
    }

    // Regression: "0" is falsy in PHP and an earlier truthy check would
    // drop it before treating it as a valid parameter value.
    public function testRequiredParameterAcceptsFalsyValue()
    {
        $router = new RadixRouter();

        $router->add('GET', '/items/:id', 'handler');
        $info = $router->lookup('GET', '/items/0');

        $this->assertEquals(200, $info['code']);
    }

    public function testEmptyStringPath()
    {
        $router = new RadixRouter();
        $router->add('GET', '', 'root_handler');

        $info = $router->lookup('GET', '');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('root_handler', $info['handler']);
    }

    public function testPathWithoutForwardSlashPrefix()
    {
        $router = new RadixRouter();
        $router->add('GET', 'no-slash', 'handler');

        $info = $router->lookup('GET', 'no-slash');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testPatternWithoutLeadingSlash()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', ltrim($type['pattern'], '/'), 'handler');
            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc']);
        }
    }
}
