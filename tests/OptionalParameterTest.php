<?php

use Wilaak\Http\RadixRouter;

class OptionalParameterTest extends RadixRouterTestCase
{
    public function testOptionalParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/hello/:name?', 'handler');

        $info1 = $router->lookup('GET', '/hello');
        $info2 = $router->lookup('GET', '/hello/alice');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals([], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('handler', $info2['handler']);
        $this->assertEquals(['name' => 'alice'], $info2['params']);
    }

    public function testChainedOptionalParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/archive/:year?/:month?', 'handler');

        $info1 = $router->lookup('GET', '/archive');
        $info2 = $router->lookup('GET', '/archive/2024');
        $info3 = $router->lookup('GET', '/archive/2024/06');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals([], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals(['year' => '2024'], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals(['year' => '2024', 'month' => '06'], $info3['params']);
    }

    public function testChainedOptionalParametersOnSingleCharacterPrefix()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/:b?/:c?', 'handler');
        $info1 = $router->lookup('GET', '/a');
        $info2 = $router->lookup('GET', '/a/1');
        $info3 = $router->lookup('GET', '/a/1/2');
        $this->assertEquals([], $info1['params']);
        $this->assertEquals(['b' => '1'], $info2['params']);
        $this->assertEquals(['b' => '1', 'c' => '2'], $info3['params']);
    }

    public function testRequiredFollowedByOptional()
    {
        $router = new RadixRouter();
        $router->add('GET', '/mix/:required/:optional?', 'handler');

        $info = $router->lookup('GET', '/mix/foo/bar');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals(['required' => 'foo', 'optional' => 'bar'], $info['params']);

        $info = $router->lookup('GET', '/mix/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals(['required' => 'foo'], $info['params']);

        $info = $router->lookup('GET', '/mix/');
        $this->assertEquals(404, $info['code']);
    }
}
