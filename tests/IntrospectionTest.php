<?php

use Wilaak\Http\RadixRouter;

class IntrospectionTest extends RadixRouterTestCase
{
    public function testListReturnsAllRegisteredRoutesSorted()
    {
        $router = new RadixRouter();

        $router->add('GET',    '/users/:id',       'UserController@show');
        $router->add('DELETE', '/users/:id',       'UserController@delete');
        $router->add('GET',    '/files/:path*',    'FileController@show');
        $router->add('POST',   '/files/:path+',    'FileController@upload');
        $router->add('GET',    '/posts/:id?',      'PostController@show');
        $router->add('GET',    '/users',           'UserController@index');
        $router->add('POST',   '/users',           'UserController@store');

        $expected = [
            ['method' => 'GET',    'pattern' => '/files/:path*', 'handler' => 'FileController@show'],
            ['method' => 'POST',   'pattern' => '/files/:path+', 'handler' => 'FileController@upload'],
            ['method' => 'GET',    'pattern' => '/posts/:id?',   'handler' => 'PostController@show'],
            ['method' => 'GET',    'pattern' => '/users',        'handler' => 'UserController@index'],
            ['method' => 'POST',   'pattern' => '/users',        'handler' => 'UserController@store'],
            ['method' => 'DELETE', 'pattern' => '/users/:id',    'handler' => 'UserController@delete'],
            ['method' => 'GET',    'pattern' => '/users/:id',    'handler' => 'UserController@show'],
        ];

        $this->assertEquals($expected, $router->list());
    }

    public function testListFilteredByPathReturnsOnlyMatchingRoutes()
    {
        $router = new RadixRouter();

        $router->add('GET',    '/users/:id',    'UserController@show');
        $router->add('DELETE', '/users/:id',    'UserController@delete');
        $router->add('GET',    '/users',        'UserController@index');
        $router->add('POST',   '/users',        'UserController@store');
        $router->add('GET',    '/files/:path*', 'FileController@show');
        $router->add('POST',   '/files/:path+', 'FileController@upload');

        $expected = [
            ['method' => 'GET',    'pattern' => '/users/:id', 'handler' => 'UserController@show'],
            ['method' => 'DELETE', 'pattern' => '/users/:id', 'handler' => 'UserController@delete'],
        ];

        $this->assertEquals($expected, $router->list('/users/123'));
    }

    public function testListFilteredByPathReturnsMatchingWildcardRoutes()
    {
        $router = new RadixRouter();

        $router->add('GET',  '/files/:path*', 'FileController@show');
        $router->add('POST', '/files/:path+', 'FileController@upload');

        $expected = [
            ['method' => 'GET',  'pattern' => '/files/:path*', 'handler' => 'FileController@show'],
            ['method' => 'POST', 'pattern' => '/files/:path+', 'handler' => 'FileController@upload'],
        ];
        $this->assertEquals($expected, $router->list('/files/a/b/c'));

        $expectedRootOnly = [
            ['method' => 'GET', 'pattern' => '/files/:path*', 'handler' => 'FileController@show'],
        ];
        $this->assertEquals($expectedRootOnly, $router->list('/files'));
    }

    public function testMethodsReturnsAllowedHttpMethodsForPath()
    {
        $router = new RadixRouter();
        $router->add('GET',    '/resource',     'get_handler');
        $router->add('POST',   '/resource',     'post_handler');
        $router->add('DELETE', '/resource/:id', 'delete_handler');

        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'POST'], $router->methods('/resource'));
        $this->assertEqualsCanonicalizing(['DELETE'], $router->methods('/resource/123'));
        $this->assertEquals([], $router->methods('/nonexistent'));

        $router->add('PUT',   '/something', 'put_handler');
        $router->add('PATCH', '/something', 'patch_handler');
        $this->assertEqualsCanonicalizing(['PUT', 'PATCH'], $router->methods('/something'));
    }

    public function testListDedupesOptionalParameterExpansions()
    {
        $router = new RadixRouter();
        $router->add('GET', '/archive/:year?/:month?', 'archive_handler');

        $routes = $router->list();

        $this->assertSame([
            [
                'method'  => 'GET',
                'pattern' => '/archive/:year?/:month?',
                'handler' => 'archive_handler',
            ],
        ], $routes);
    }

    public function testMethodsAcrossAllPatternTypes()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET',  $type['pattern'], 'get_handler');
            $router->add('POST', $type['pattern'], 'post_handler');
            $this->assertEqualsCanonicalizing(
                ['GET', 'HEAD', 'POST'],
                $router->methods($type['lookup']),
                $type['desc']
            );
        }
    }

    public function testEmptyRouterIntrospection()
    {
        $router = new RadixRouter();

        $this->assertSame([], $router->methods('/anything'));
        $this->assertSame([], $router->list());
        $this->assertSame([], $router->list('/anything'));
    }
}
