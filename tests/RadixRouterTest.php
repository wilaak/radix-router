<?php

use PHPUnit\Framework\TestCase;
use Wilaak\Http\RadixRouter;

class RadixRouterTest extends TestCase
{
    private static function patternTypes(): array
    {
        return [
            [
                'pattern' => '/resource',
                'lookup'  => '/resource',
                'params'  => [],
                'desc'    => 'static',
            ],
            [
                'pattern' => '/resource/:id',
                'lookup'  => '/resource/123',
                'params'  => ['id' => '123'],
                'desc'    => 'required param',
            ],
            [
                'pattern' => '/resource/:opt?',
                'lookup'  => '/resource/value',
                'params'  => ['opt' => 'value'],
                'desc'    => 'optional param',
            ],
            [
                'pattern' => '/resource/:opt?/:opt2?/:opt3?',
                'lookup'  => '/resource/value1/value2',
                'params'  => ['opt' => 'value1', 'opt2' => 'value2'],
                'desc'    => 'multiple optional params',
            ],
            [
                'pattern' => '/resource/:opt?/:opt2?/:opt3?',
                'lookup'  => '/resource/value1/value2/value3',
                'params'  => ['opt' => 'value1', 'opt2' => 'value2', 'opt3' => 'value3'],
                'desc'    => 'multiple optional params',
            ],
            [
                'pattern' => '/resource/:opt?/:opt2?/:opt3?',
                'lookup'  => '/resource/value1',
                'params'  => ['opt' => 'value1'],
                'desc'    => 'multiple optional params',
            ],
            [
                'pattern' => '/resource/:rest*',
                'lookup'  => '/resource/one/two',
                'params'  => ['rest' => 'one/two'],
                'desc'    => 'wildcard (*)',
            ],
            [
                'pattern' => '/resource/:rest+',
                'lookup'  => '/resource/one/two',
                'params'  => ['rest' => 'one/two'],
                'desc'    => 'required wildcard (+)',
            ],
            [
                'pattern' => '/resource/:a/:b',
                'lookup'  => '/resource/foo/bar',
                'params'  => ['a' => 'foo', 'b' => 'bar'],
                'desc'    => 'multiple required params',
            ],
            [
                'pattern' => '/resource/:id/:opt?',
                'lookup'  => '/resource/123/value',
                'params'  => ['id' => '123', 'opt' => 'value'],
                'desc'    => 'required + optional',
            ],
            [
                'pattern' => '/resource/:id/:rest*',
                'lookup'  => '/resource/123/one/two',
                'params'  => ['id' => '123', 'rest' => 'one/two'],
                'desc'    => 'required + wildcard (*)',
            ],
            [
                'pattern' => '/resource/:id/:rest+',
                'lookup'  => '/resource/123/one/two',
                'params'  => ['id' => '123', 'rest' => 'one/two'],
                'desc'    => 'required + required wildcard (+)',
            ],
        ];
    }

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

    public function testWildcardParameters()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/download/:id', 'static_handler');
        $router->add('GET', '/files/:path*', 'wildcard_handler');
        $router->add('GET', '/files/required/:path+', 'required_wildcard_handler');

        // Optional wildcard tests (zero or more)
        $info1 = $router->lookup('GET', '/files');
        $info2 = $router->lookup('GET', '/files/');
        $info3 = $router->lookup('GET', '/files/download/123');
        $info4 = $router->lookup('GET', '/files/anything/else');
        $info5 = $router->lookup('GET', '/files/download/123/456');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('wildcard_handler', $info1['handler']);
        $this->assertEquals(['path' => ''], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('wildcard_handler', $info2['handler']);
        $this->assertEquals(['path' => ''], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals('static_handler', $info3['handler']);
        $this->assertEquals(['id' => '123'], $info3['params']);

        $this->assertEquals(200, $info4['code']);
        $this->assertEquals('wildcard_handler', $info4['handler']);
        $this->assertEquals(['path' => 'anything/else'], $info4['params']);

        $this->assertEquals(200, $info5['code']);
        $this->assertEquals('wildcard_handler', $info5['handler']);
        $this->assertEquals(['path' => 'download/123/456'], $info5['params']);

        // Required wildcard tests (one or more)
        $info6 = $router->lookup('GET', '/files/required/a');
        $info7 = $router->lookup('GET', '/files/required/a/b/c');
        $info8 = $router->lookup('GET', '/files/required');

        $this->assertEquals(200, $info6['code']);
        $this->assertEquals('required_wildcard_handler', $info6['handler']);
        $this->assertEquals(['path' => 'a'], $info6['params']);

        $this->assertEquals(200, $info7['code']);
        $this->assertEquals('required_wildcard_handler', $info7['handler']);
        $this->assertEquals(['path' => 'a/b/c'], $info7['params']);

        $this->assertEquals('wildcard_handler', $info8['handler']);
    }

    public function testWildcardAndParameterMixing()
    {
        $router = new RadixRouter();
        // Wildcard at the end, with a parameter before
        $router->add('GET', '/foo/:bar/:rest*', 'handler1');
        // Wildcard at the end, with static before
        $router->add('GET', '/static/:rest*', 'handler2');
        // Wildcard fallback
        $router->add('GET', '/:rest*', 'handler3');
        // Required wildcard with nothing before (different segment)
        $router->add('GET', '/required/:rest+', 'handler4');
        // Required wildcard with parameter before (different segment)
        $router->add('GET', '/bar/:id/:rest+', 'handler5');

        // /foo/abc/def/ghi -> bar=abc, rest=def/ghi
        $info = $router->lookup('GET', '/foo/abc/def/ghi');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler1', $info['handler']);
        $this->assertEquals(['bar' => 'abc', 'rest' => 'def/ghi'], $info['params']);

        // /foo/abc -> bar=abc, rest=''
        $info = $router->lookup('GET', '/foo/abc');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler1', $info['handler']);
        $this->assertEquals(['bar' => 'abc', 'rest' => ''], $info['params']);

        // /static/one/two/three -> rest=one/two/three
        $info = $router->lookup('GET', '/static/one/two/three');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler2', $info['handler']);
        $this->assertEquals(['rest' => 'one/two/three'], $info['params']);

        // /static/ -> rest=''
        $info = $router->lookup('GET', '/static/');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler2', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);

        // /anything/else -> rest=anything/else
        $info = $router->lookup('GET', '/anything/else');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler3', $info['handler']);
        $this->assertEquals(['rest' => 'anything/else'], $info['params']);

        // / -> rest=''
        $info = $router->lookup('GET', '/');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler3', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);

        // Required wildcard with nothing before
        $info = $router->lookup('GET', '/required/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler4', $info['handler']);
        $this->assertEquals(['rest' => 'foo'], $info['params']);

        $info = $router->lookup('GET', '/required/foo/bar/baz');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler4', $info['handler']);
        $this->assertEquals(['rest' => 'foo/bar/baz'], $info['params']);

        $info = $router->lookup('GET', '/required');
        $this->assertEquals('handler3', $info['handler']);

        // Required wildcard with parameter before
        $info = $router->lookup('GET', '/bar/123/abc');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler5', $info['handler']);
        $this->assertEquals(['id' => '123', 'rest' => 'abc'], $info['params']);

        $info = $router->lookup('GET', '/bar/123/abc/def');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler5', $info['handler']);
        $this->assertEquals(['id' => '123', 'rest' => 'abc/def'], $info['params']);

        $info = $router->lookup('GET', '/bar/123');
        $this->assertEquals('handler3', $info['handler']);
    }

    public function testOptionalAndParameterMixing()
    {
        $router = new RadixRouter();
        // Route with a required parameter followed by an optional parameter
        $router->add('GET', '/mix/:required/:optional?', 'handler');

        // /mix/foo/bar -> required=foo, optional=bar
        $info = $router->lookup('GET', '/mix/foo/bar');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
        $this->assertEquals(['required' => 'foo', 'optional' => 'bar'], $info['params']);

        // /mix/foo -> required=foo, optional missing
        $info = $router->lookup('GET', '/mix/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('handler', $info['handler']);
        $this->assertEquals(['required' => 'foo'], $info['params']);

        // /mix/ (should not match, required param missing)
        $info = $router->lookup('GET', '/mix/');
        $this->assertEquals(404, $info['code']);
    }

    public function testMethodNotAllowed()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');
            $router->add('POST', $type['pattern'], 'post_handler');

            $info = $router->lookup('PUT', $type['lookup']);
            $this->assertEquals(405, $info['code'], $type['desc']);
            $this->assertEqualsCanonicalizing(['GET', 'POST', 'HEAD'], $info['allowed_methods'], $type['desc']);
        }
    }

    public function testRouteConflictThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/conflict', 'handler1');
        $router->add('GET', '/conflict', 'handler2');
    }

    public function testParameterRouteConflictThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:id', 'handler1');
        $router->add('GET', '/foo/:asd', 'handler2');
    }

    public function testWildcardRouteConflictThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/bar/:rest*', 'handler1');
        $router->add('GET', '/bar/:rest+', 'handler2');
    }

    public function testWildcardOnlyAtEnd()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar*/baz', 'bad_handler');
    }

    public function testRequiredWildcardOnlyAtEnd()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar+/baz', 'bad_handler');
    }

    public function testOptionalOnlyAtEnd()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar?/baz', 'bad_handler');
    }

    public function testMixingWildcardAndOptionalMarker()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar*?', 'bad_handler');
    }

    public function testMixingRequiredWildcardAndOptionalMarker()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar+?', 'bad_handler');
    }

    public function testDuplicateParameterNameThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:id/bar/:id', 'handler');
    }

    public function testDoubleSlashInPatternThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '//foo', 'handler');
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

    public function testEmptyMethodArrayThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add([], '/bad', 'handler');
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

    public function testMultipleMethodsArray()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add(['GET', 'POST'], $type['pattern'], 'multi_handler');

            $info1 = $router->lookup('GET', $type['lookup']);
            $info2 = $router->lookup('POST', $type['lookup']);
            $this->assertEquals(200, $info1['code'], $type['desc'] . ': GET code');
            $this->assertEquals(200, $info2['code'], $type['desc'] . ': POST code');
            $this->assertEquals('multi_handler', $info1['handler'], $type['desc'] . ': GET handler');
            $this->assertEquals('multi_handler', $info2['handler'], $type['desc'] . ': POST handler');
        }
    }

    public function testCaseInsensitiveMethod()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('get', $type['pattern'], 'handler');
            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc']);
            $this->assertEquals('handler', $info['handler'], $type['desc']);
        }
    }

    public function testTrailingSlashNormalization()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'handler');
            $this->assertEquals(200, $router->lookup('GET', $type['lookup'] . '/')['code'], $type['desc'] . ': trailing slash');
            $this->assertEquals(200, $router->lookup('GET', $type['lookup'])['code'], $type['desc'] . ': no trailing slash');
        }
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

    public function testOptionalParameterVariants()
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

    public function testWildcardWithNoSegments()
    {
        $router = new RadixRouter();
        $router->add('GET', '/wild/:rest*', 'handler');
        $info = $router->lookup('GET', '/wild');
        $this->assertEquals(['rest' => ''], $info['params']);
    }

    public function testParameterNameValidation()
    {
        $router = new RadixRouter();

        // Invalid parameter names
        $invalidPatterns = [
            '/foo/:',          // Empty parameter name
            '/foo/:1bar',      // Starts with number
            '/foo/:bar-baz',   // Contains dash
            '/foo/:bar baz',   // Contains space
            '/foo/:bar$',      // Contains special char
        ];

        foreach ($invalidPatterns as $pattern) {
            try {
                $router->add('GET', $pattern, 'handler');
                $this->fail("Pattern '$pattern' should throw InvalidArgumentException");
            } catch (\InvalidArgumentException $e) {
                $this->assertTrue(true); // Exception thrown as expected
            }
        }

        // Valid parameter names
        $validPatterns = [
            ['/foo/:_bar', ['_bar' => 'value'], '/foo/value'],
            ['/foo/:bar123', ['bar123' => 'val'], '/foo/val'],
            ['/foo/:_bar_123', ['_bar_123' => 'val'], '/foo/val'],
            ['/foo/:BarBaz', ['BarBaz' => 'val'], '/foo/val'],
        ];

        foreach ($validPatterns as [$pattern, $expectedParams, $lookupPath]) {
            $router = new RadixRouter();
            $router->add('GET', $pattern, 'handler');
            $info = $router->lookup('GET', $lookupPath);
            $this->assertEquals($expectedParams, $info['params'], "Pattern '$pattern' failed");
        }
    }

    public function testBenchmarking()
    {
        $benchmarks = ['avatax', 'simple', 'bitbucket'];

        foreach ($benchmarks as $benchmark) {
            $routes = require __DIR__ . "/../benchmark/routes/" . $benchmark . ".php";

            // Convert curly braces to colon syntax for RadixRouter compatibility
            foreach ($routes as &$path) {
                $path = str_replace('{', ':', $path);
                $path = str_replace('}', '', $path);
            }
            unset($path);

            $registrationStart = microtime(true);
            $r = new RadixRouter();
            foreach ($routes as $path) {
                $r->add('GET', $path, 'handler');
            }
            $registrationEnd = microtime(true);
            $registrationDuration = $registrationEnd - $registrationStart;

            $routeCount = count($routes);
            $iterations = $routeCount * 2;

            $start = microtime(true);
            for ($i = 0; $i < $iterations; $i++) {
                $index = $i % $routeCount;
                $path = $routes[$index];
                $info = $r->lookup('GET', $path);
                $this->assertEquals(200, $info['code'], "Route not found: $path ($benchmark)");
                $this->assertEquals('handler', $info['handler']);
            }
            $end = microtime(true);
            $duration = $end - $start;

            // Assert that registration and lookup durations are reasonable (arbitrary upper bounds)
            $this->assertLessThan(2, $registrationDuration, "Registration took too long for $benchmark");
            $this->assertLessThan(5, $duration, "Lookup took too long for $benchmark");
        }
    }

    public function testWildcardFallback()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/bar', 'static_handler');
        $router->add('GET', '/foo/:param', 'param_handler');
        $router->add('GET', '/foo/:rest*', 'wildcard_handler');

        // Should match static
        $info = $router->lookup('GET', '/foo/bar');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('static_handler', $info['handler']);

        // Should match parameter
        $info = $router->lookup('GET', '/foo/baz');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('param_handler', $info['handler']);
        $this->assertEquals(['param' => 'baz'], $info['params']);

        // Should fallback to wildcard
        $info = $router->lookup('GET', '/foo/bar/baz/qux');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => 'bar/baz/qux'], $info['params']);

        // Should fallback to wildcard for /foo only
        $info = $router->lookup('GET', '/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);
    }

    public function testWildcardFallbackWithOverlappingDynamicRoutes()
    {
        $router = new RadixRouter();
        $router->add('GET', '/api/:version/users/:id', 'user_handler');
        $router->add('GET', '/api/:version/:rest*', 'wildcard_handler');

        // Should match the specific dynamic route
        $info = $router->lookup('GET', '/api/v1/users/42');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('user_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'id' => '42'], $info['params']);

        // Should fallback to wildcard for other paths
        $info = $router->lookup('GET', '/api/v1/other/path');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'rest' => 'other/path'], $info['params']);

        // Should fallback to wildcard for /api/v2
        $info = $router->lookup('GET', '/api/v2');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v2', 'rest' => ''], $info['params']);
    }

    public function testParameterMatchingPriority()
    {
        $router = new RadixRouter();
        $router->add('GET', '/priority/static', 'static_handler');
        $router->add('GET', '/priority/:param', 'param_handler');
        $router->add('GET', '/priority/:rest*', 'wildcard_handler');

        // Should match static first
        $info = $router->lookup('GET', '/priority/static');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('static_handler', $info['handler']);

        // Should match parameter next
        $info = $router->lookup('GET', '/priority/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('param_handler', $info['handler']);
        $this->assertEquals(['param' => 'foo'], $info['params']);

        // Should match wildcard last
        $info = $router->lookup('GET', '/priority/foo/bar/baz');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => 'foo/bar/baz'], $info['params']);
    }

    public function testFallbackWildcardDoesNotGetDynamicParams()
    {
        $router = new RadixRouter();
        $router->add('GET', '/api/:version/users/:id', 'user_handler');
        $router->add('GET', '/api/:version/:rest*', 'wildcard_handler');

        // Should match the specific dynamic route
        $info = $router->lookup('GET', '/api/v1/users/42');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('user_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'id' => '42'], $info['params']);

        // Should fallback to wildcard, and only get version and rest
        $info = $router->lookup('GET', '/api/v1/other/path');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'rest' => 'other/path'], $info['params']);

        // Should fallback to wildcard, and not get id param
        $info = $router->lookup('GET', '/api/v1/users/42/extra');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'rest' => 'users/42/extra'], $info['params']);
    }

    public function testRouteList()
    {
        $router = new RadixRouter();

        $router->add('GET', '/users/:id', 'UserController@show');
        $router->add('DELETE', '/users/:id', 'UserController@delete');
        $router->add('GET', '/files/:path*', 'FileController@show');
        $router->add('GET', '/posts/:id?', 'PostController@show');
        $router->add('GET', '/users', 'UserController@index');
        $router->add('POST', '/users', 'UserController@store');

        $routes = $router->list();

        $expected = [
            [
                'method' => 'GET',
                'pattern' => '/files/:path*',
                'handler' => 'FileController@show',
            ],
            [
                'method' => 'GET',
                'pattern' => '/posts/:id?',
                'handler' => 'PostController@show',
            ],
            [
                'method' => 'GET',
                'pattern' => '/users',
                'handler' => 'UserController@index',
            ],
            [
                'method' => 'POST',
                'pattern' => '/users',
                'handler' => 'UserController@store',
            ],
            [
                'method' => 'DELETE',
                'pattern' => '/users/:id',
                'handler' => 'UserController@delete',
            ],
            [
                'method' => 'GET',
                'pattern' => '/users/:id',
                'handler' => 'UserController@show',
            ],
        ];

        $this->assertEquals($expected, $routes);


        $routes = $router->list('/users/123');

        $expected = [
            [
                'method' => 'GET',
                'pattern' => '/users/:id',
                'handler' => 'UserController@show',
            ],
            [
                'method' => 'DELETE',
                'pattern' => '/users/:id',
                'handler' => 'UserController@delete',
            ],
        ];
        $this->assertEquals($expected, $routes);
    }

    public function testListingMethodsForPath()
    {
        // Multi-route scenario: verify methods() is specific to the matched route
        $router = new RadixRouter();
        $router->add('GET', '/resource', 'get_handler');
        $router->add('POST', '/resource', 'post_handler');
        $router->add('DELETE', '/resource/:id', 'delete_handler');

        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'POST'], $router->methods('/resource'));
        $this->assertEqualsCanonicalizing(['DELETE'], $router->methods('/resource/123'));
        $this->assertEquals([], $router->methods('/nonexistent'));

        $router->add('PUT', '/something', 'put_handler');
        $router->add('PATCH', '/something', 'patch_handler');
        $this->assertEqualsCanonicalizing(['PUT', 'PATCH'], $router->methods('/something'));

        // Verify methods() returns correct results for every pattern type
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');
            $router->add('POST', $type['pattern'], 'post_handler');
            $this->assertEqualsCanonicalizing(
                ['GET', 'HEAD', 'POST'],
                $router->methods($type['lookup']),
                $type['desc']
            );
        }
    }

    public function testRequiredParameterEvaluation()
    {
        $router = new RadixRouter();

        $router->add('GET', '/items/:id', 'handler');
        $info1 = $router->lookup('GET', '/items/0');

        $this->assertEquals(200, $info1['code']);
    }

    public function testOptionalWildcardPrioritization()
    {
        $router = new RadixRouter();

        $router->add('DELETE', '/:test+', 'required');
        $router->add('POST', '/:test*', 'optional');
        $router->add('GET', '/demo/:test+', 'demo_required');

        $allowedMethods = $router->lookup('DELETE', '/')['allowed_methods'];
        $this->assertEquals(['POST'], $allowedMethods);

        $info = $router->lookup('POST', '/demo');
        $this->assertEquals('optional', $info['handler']);


        $info = $router->lookup('DELETE', '/demo');
        $this->assertEquals('required', $info['handler']);
    }

    public function testOptionalWildcardListingPrioritization()
    {
        $router = new RadixRouter();

        $router->add('GET', '/:test*', 'optional');
        $router->add('POST', '/:test+', 'required');

        $routes = $router->list('/');

        $this->assertCount(1, $routes);
    }

    public function testSpecialAnyMethodFallback()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('*', $type['pattern'], 'all_methods_handler');
            $router->add('GET', $type['pattern'], 'get_handler');

            $info = $router->lookup('POST', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': POST fallback code');
            $this->assertEquals('all_methods_handler', $info['handler'], $type['desc'] . ': POST fallback handler');
            $this->assertEquals($type['params'], $info['params'], $type['desc'] . ': POST fallback params');

            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': GET code');
            $this->assertEquals('get_handler', $info['handler'], $type['desc'] . ': GET handler');
            $this->assertEquals($type['params'], $info['params'], $type['desc'] . ': GET params');

            $this->assertEquals(true, $router->methods($type['lookup']) == $router->allowedMethods, $type['desc'] . ': methods listing');

            $list = $router->list($type['lookup']);
            $this->assertEquals([
                [
                    'method' => '*',
                    'pattern' => $type['pattern'],
                    'handler' => 'all_methods_handler',
                ],
                [
                    'method' => 'GET',
                    'pattern' => $type['pattern'],
                    'handler' => 'get_handler',
                ],
            ], $list, $type['desc'] . ': route listing');
        }
    }

    public function testHeadMethodFallback()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');

            $info = $router->lookup('HEAD', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': HEAD fallback code');
            $this->assertEquals('get_handler', $info['handler'], $type['desc'] . ': HEAD fallback handler');

            $methods = $router->methods($type['lookup']);
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $methods, $type['desc'] . ': methods before explicit HEAD');

            $router->add('HEAD', $type['pattern'], 'head_handler');
            $info = $router->lookup('HEAD', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': explicit HEAD code');
            $this->assertEquals('head_handler', $info['handler'], $type['desc'] . ': explicit HEAD handler');

            $methods = $router->methods($type['lookup']);
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $methods, $type['desc'] . ': methods after explicit HEAD');
        }
    }

    public function testCanStillRegisterHeadExplicitly()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'get_handler');
            $router->add('HEAD', $type['pattern'], 'head_handler');

            $info = $router->lookup('HEAD', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': HEAD code');
            $this->assertEquals('head_handler', $info['handler'], $type['desc'] . ': HEAD handler');

            $info = $router->lookup('GET', $type['lookup']);
            $this->assertEquals(200, $info['code'], $type['desc'] . ': GET code');
            $this->assertEquals('get_handler', $info['handler'], $type['desc'] . ': GET handler');

            $methods = $router->methods($type['lookup']);
            $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $methods, $type['desc'] . ': methods listing');
        }
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

        // Static nodes take precedence over parameter nodes.
        // When matching /test, the router traverses to the static node (/test)
        // but finds no handler since the parameter node requires a non-empty segment

        // This test ensures that if no route is found at a static node we will
        // fall back to the parameter node at the same path level.

        $info = $router->lookup('GET', '/test');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals("First: /:test, param = test", $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/test/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals("Second: /test/:test, param = foo", $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals("First: /:test, param = foo", $info['handler'](...$info['params']));

        $router = new RadixRouter();
        $router->add('GET', '/:foo', function ($foo) {
            return "Foo";
        });
        $router->add('GET', '/bar/:foo*', function ($foo) {
            return "Bar";
        });

        $info = $router->lookup('GET', '/bar');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals("Foo", $info['handler'](...$info['params']));

        $info = $router->lookup('GET', '/bar/foo');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals("Bar", $info['handler'](...$info['params']));
    }
}
