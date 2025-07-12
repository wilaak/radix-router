<?php
use PHPUnit\Framework\TestCase;
use Wilaak\Http\RadixRouter;

class RadixRouterTest extends TestCase
{
    public function testStaticRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/about', 'handler');
        $info = $router->lookup('GET', '/about');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testParameterizedRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/user/:id/:type', 'handler');
        $info = $router->lookup('GET', '/user/123/admin');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testMultipleParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/posts/:post/comments/:comment', 'handler');
        $info = $router->lookup('GET', '/posts/42/comments/7');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testOptionalParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/posts/:id/:type?', 'handler');
        $info1 = $router->lookup('GET', '/posts/abc/editor');
        $info2 = $router->lookup('GET', '/posts/abc/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('handler', $info2['handler']);
    }

    public function testWildcardParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:folder/:path*', 'handler');
        $info1 = $router->lookup('GET', '/files/static/dog/pic.jpg');
        $info2 = $router->lookup('GET', '/files/static/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('handler', $info2['handler']);
    }

    public function testMethodNotAllowed()
    {
        $router = new RadixRouter();
        $router->add('GET', '/about', 'handler');
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
