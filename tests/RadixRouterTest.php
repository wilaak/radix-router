<?php

use PHPUnit\Framework\TestCase;
use Wilaak\Http\RadixRouter;

class RadixRouterTest extends TestCase
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
        $this->assertEquals(['gordon'], $info3['params']);
    }

    public function testOptionalParameters()
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
        $this->assertEquals(['alice'], $info2['params']);
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
        $this->assertEquals(['2024'], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals(['2024', '06'], $info3['params']);
    }

    public function testMixedOptionalAndStaticSegments()
    {
        $router = new RadixRouter();
        $router->add('GET', '/blog/:year?/:month?/:slug?/comments/:action?', 'handler');

        $info1 = $router->lookup('GET', '/blog');
        $info2 = $router->lookup('GET', '/blog/2024');
        $info3 = $router->lookup('GET', '/blog/2024/06');
        $info4 = $router->lookup('GET', '/blog/2024/06/my-post/comments');
        $info5 = $router->lookup('GET', '/blog/2024/06/my-post/comments/edit');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals([], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals(['2024'], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals(['2024', '06'], $info3['params']);

        $this->assertEquals(200, $info4['code']);
        $this->assertEquals(['2024', '06', 'my-post'], $info4['params']);

        $this->assertEquals(200, $info5['code']);
        $this->assertEquals(['2024', '06', 'my-post', 'edit'], $info5['params']);
    }

    public function testWildcardParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/download/:id', 'static_handler');
        $router->add('GET', '/files/:path*', 'wildcard_handler');

        $info1 = $router->lookup('GET', '/files');
        $info2 = $router->lookup('GET', '/files/');
        $info3 = $router->lookup('GET', '/files/download/123');
        $info4 = $router->lookup('GET', '/files/download/overlapping/readme.txt');
        $info5 = $router->lookup('GET', '/files/anything/else/');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('wildcard_handler', $info1['handler']);
        $this->assertEquals([''], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('wildcard_handler', $info2['handler']);
        $this->assertEquals([''], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals('static_handler', $info3['handler']);
        $this->assertEquals(['123'], $info3['params']);

        $this->assertEquals(404, $info4['code']);

        $this->assertEquals(200, $info5['code']);
        $this->assertEquals('wildcard_handler', $info5['handler']);
        $this->assertEquals(['anything/else'], $info5['params']);
    }

    public function testMethodNotAllowed()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo', 'get_handler');
        $router->add('POST', '/foo', 'post_handler');

        $info = $router->lookup('PUT', '/foo');
        $this->assertEquals(405, $info['code']);
        $this->assertEqualsCanonicalizing(['GET', 'POST'], $info['allowed_methods']);
    }

    public function testRouteConflictThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/conflict', 'handler1');
        $router->add('GET', '/conflict', 'handler2');
    }

    public function testWildcardOnlyAtEnd()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar*/baz', 'bad_handler');
    }

    public function testEmptyPatternAndRoot()
    {
        $router = new RadixRouter();
        $router->add('GET', '', 'root_handler');
        $info = $router->lookup('GET', '/');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('root_handler', $info['handler']);
    }

    public function testMultipleMethodsArray()
    {
        $router = new RadixRouter();
        $router->add(['GET', 'POST'], '/multi', 'multi_handler');
        $info1 = $router->lookup('GET', '/multi');
        $info2 = $router->lookup('POST', '/multi');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('multi_handler', $info1['handler']);
        $this->assertEquals('multi_handler', $info2['handler']);
    }

    public function testCaseInsensitiveMethod()
    {
        $router = new RadixRouter();
        $router->add('get', '/case', 'handler');
        $info = $router->lookup('GET', '/case');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testTrailingSlashIgnored()
    {
        $router = new RadixRouter();
        $router->add('GET', '/slash', 'handler');
        $info1 = $router->lookup('GET', '/slash/');
        $info2 = $router->lookup('GET', '/slash');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals(200, $info2['code']);
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
        $this->assertEquals(['baz'], $info2['params']);
    }

    public function testOptionalParameterVariants()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/:b?/:c?', 'handler');
        $info1 = $router->lookup('GET', '/a');
        $info2 = $router->lookup('GET', '/a/1');
        $info3 = $router->lookup('GET', '/a/1/2');
        $this->assertEquals([], $info1['params']);
        $this->assertEquals(['1'], $info2['params']);
        $this->assertEquals(['1', '2'], $info3['params']);
    }

    public function testWildcardWithNoSegments()
    {
        $router = new RadixRouter();
        $router->add('GET', '/wild/:rest*', 'handler');
        $info = $router->lookup('GET', '/wild');
        $this->assertEquals([''], $info['params']);
    }

    public function testInvalidMethodThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('FOOBAR', '/bad', 'handler');
    }

    public function testEmptyMethodThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('', '/bad', 'handler');
    }

    public function testParameterAndWildcardConflict()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar', 'param_handler');
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:baz*', 'wildcard_handler');
    }
}
