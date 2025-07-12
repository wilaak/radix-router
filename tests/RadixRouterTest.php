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
        $this->assertEquals(['123', 'admin'], $info['params']);
    }

    public function testMultipleParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/posts/:post/comments/:comment', 'handler');
        $info = $router->lookup('GET', '/posts/42/comments/7');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
        $this->assertEquals(['42', '7'], $info['params']);
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
        $this->assertEquals(['abc', 'editor'], $info1['params']);
        $this->assertEquals(['abc'], $info2['params']);
    }

    public function testWildcardParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:folder/:path*', 'handler');
        $info1 = $router->lookup('GET', '/files/static/dog/pic.jpg');
        $info2 = $router->lookup('GET', '/files/static/');
        $info3 = $router->lookup('GET', '/files/static/trailing/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('handler', $info2['handler']);
        $this->assertEquals(200, $info3['code']);
        $this->assertEquals('handler', $info3['handler']);

        $this->assertEquals(['static', 'dog/pic.jpg'], $info1['params']);
        $this->assertEquals(['static', ''], $info2['params']);
        $this->assertEquals(['static', 'trailing/'], $info3['params']);
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

    public function testTrailingSlashStaticRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/contact/', 'handler');
        $info1 = $router->lookup('GET', '/contact/');
        $info2 = $router->lookup('GET', '/contact');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals(404, $info2['code']);
    }

    public function testTrailingSlashParameterizedRoute()
    {
        $router = new RadixRouter();
        $router->add('GET', '/profile/:id/', 'handler');
        $info1 = $router->lookup('GET', '/profile/123/');
        $info2 = $router->lookup('GET', '/profile/123');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler', $info1['handler']);
        $this->assertEquals(['123'], $info1['params']);
        $this->assertEquals(404, $info2['code']);
    }

    public function testRouteWithAndWithoutTrailingSlash()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo', 'handler1');
        $router->add('GET', '/foo/', 'handler2');
        $info1 = $router->lookup('GET', '/foo');
        $info2 = $router->lookup('GET', '/foo/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('handler1', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('handler2', $info2['handler']);
    }

    public function testTrailingSlashWithWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/assets/:path*', 'handler');
        $info1 = $router->lookup('GET', '/assets/');
        $info2 = $router->lookup('GET', '/assets/css/style.css');
        $info3 = $router->lookup('GET', '/assets/js/');
        $this->assertEquals(200, $info1['code']);
        $this->assertEquals([''], $info1['params']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals(['css/style.css'], $info2['params']);
        $this->assertEquals(200, $info3['code']);
        $this->assertEquals(['js/'], $info3['params']);
    }

    public function testUsersAndUsersSlashAreDistinct()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users', 'users_handler');
        $router->add('GET', '/users/', 'users_slash_handler');

        $info1 = $router->lookup('GET', '/users');
        $info2 = $router->lookup('GET', '/users/');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('users_handler', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('users_slash_handler', $info2['handler']);
    }

    public function testUsersDoesNotMatchUsersSlashAndViceVersa()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users', 'users_handler');

        $info1 = $router->lookup('GET', '/users/');
        $this->assertEquals(404, $info1['code']);

        $router = new RadixRouter();
        $router->add('GET', '/users/', 'users_slash_handler');

        $info2 = $router->lookup('GET', '/users');
        $this->assertEquals(404, $info2['code']);
    }

    public function testStaticAndParameterizedRoutesDoNotInterfere()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users', 'static_handler');
        $router->add('GET', '/users/:id', 'param_handler');

        $info1 = $router->lookup('GET', '/users');
        $info2 = $router->lookup('GET', '/users/123');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('static_handler', $info1['handler']);
        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('param_handler', $info2['handler']);
        $this->assertEquals(['123'], $info2['params']);
    }

    public function testParameterizedRouteDoesNotMatchStatic()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/:id', 'param_handler');

        $info = $router->lookup('GET', '/users');
        $this->assertEquals(404, $info['code']);
    }

    public function testOptionalParameterWithTrailingSlash()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar?', 'handler');

        $info1 = $router->lookup('GET', '/foo/');
        $info2 = $router->lookup('GET', '/foo');
        $info3 = $router->lookup('GET', '/foo/baz');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals([], $info1['params']);
        $this->assertEquals(404, $info2['code']);
        $this->assertEquals(200, $info3['code']);
        $this->assertEquals(['baz'], $info3['params']);
    }

    public function testWildcardRouteDoesNotMatchStatic()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'wildcard_handler');

        $info = $router->lookup('GET', '/files');
        $this->assertEquals(404, $info['code']);
    }

    public function testWildcardRouteWithEmptySegment()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'wildcard_handler');

        $info = $router->lookup('GET', '/files/');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals([''], $info['params']);
    }

    public function testMethodCaseInsensitivity()
    {
        $router = new RadixRouter();
        $router->add('get', '/lowercase', 'handler');
        $info = $router->lookup('GET', '/lowercase');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
    }

    public function testConflictingStaticRouteThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/conflict', 'handler1');
        $router->add('GET', '/conflict', 'handler2');
    }

    public function testConflictingParameterRouteThrowsException()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/conflict/:test', 'handler1');
        $router->add('GET', '/conflict/:test?', 'handler2');
    }
}
