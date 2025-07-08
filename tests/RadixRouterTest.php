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
        $router->add('GET', '/user/:id', function ($id) { return $id; });
        $info = $router->lookup('GET', '/user/123');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('123', $info['handler'](...$info['params']));
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
        $router->add('GET', '/posts/:id?', function ($id = null) { return $id; });
        $info1 = $router->lookup('GET', '/posts/abc');
        $info2 = $router->lookup('GET', '/posts/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('abc', $info1['handler'](...$info1['params']));
        $this->assertEquals(200, $info2['code']);
        $this->assertNull($info2['handler'](...$info2['params']));
    }

    public function testWildcardParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', function ($path) { return $path; });
        $info1 = $router->lookup('GET', '/files/static/dog.jpg');
        $info2 = $router->lookup('GET', '/files/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('static/dog.jpg', $info1['handler'](...$info1['params']));
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('', $info2['handler'](...$info2['params']));
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
