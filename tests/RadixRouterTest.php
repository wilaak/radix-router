<?php
use PHPUnit\Framework\TestCase;
use Wilaak\Http\RadixRouter;

class RadixRouterTest extends TestCase
{
    public function testStaticRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/about', function () { return 'about'; });
        $info = $router->lookup('GET', '/about');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('about', $info['handler']());
    }

    public function testParameterizedRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/user/:id/:type', function ($id, $type) { return [$id, $type]; });
        $info = $router->lookup('GET', '/user/123/admin');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals(['123', 'admin'], $info['handler'](...$info['params']));
    }

    public function testMultipleParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/posts/:post/comments/:comment', function ($post, $comment) { return [$post, $comment]; });
        $info = $router->lookup('GET', '/posts/42/comments/7');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals(['42', '7'], $info['handler'](...$info['params']));
    }

    public function testOptionalParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/posts/:id?/:type?', function ($id = null, $type = null) { return [$id, $type]; });
        $info1 = $router->lookup('GET', '/posts/abc/editor');
        $info2 = $router->lookup('GET', '/posts/abc');
        $info3 = $router->lookup('GET', '/posts/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals(['abc', 'editor'], $info1['handler'](...$info1['params']));
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals(['abc', null], $info2['handler'](...$info2['params']));
        $this->assertEquals(200, $info3['code']);
        $this->assertEquals([null, null], $info3['handler'](...$info3['params']));
    }

    public function testWildcardParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:folder/:path*', function ($folder, $path) { return [$folder, $path]; });
        $info1 = $router->lookup('GET', '/files/static/dog/pic.jpg');
        $info2 = $router->lookup('GET', '/files/static/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals(['static', 'dog/pic.jpg'], $info1['handler'](...$info1['params']));
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals(['static', ''], $info2['handler'](...$info2['params']));
    }

    public function testMethodNotAllowed()
    {
        $router = new RadixRouter();
        $router->add('GET', '/about', function () { return 'about'; });
        $info = $router->lookup('POST', '/about');
        $this->assertEquals(405, $info['code']);
        $this->assertContains('GET', $info['allowed_methods']);
    }

    public function testNotFound()
    {
        $router = new RadixRouter();
        $info = $router->lookup('GET', '/notfound');
        $this->assertEquals(404, $info['code']);
    }
}
