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

        $info1 = $router->lookup('GET', '/files');
        $info2 = $router->lookup('GET', '/files/');
        $info3 = $router->lookup('GET', '/files/download/123');
        $info5 = $router->lookup('GET', '/files/anything/else/');
        $info6 = $router->lookup('GET', '/files/download/123/456');

        $this->assertEquals(200, $info1['code']);
        $this->assertEquals('wildcard_handler', $info1['handler']);
        $this->assertEquals(['path' => ''], $info1['params']);

        $this->assertEquals(200, $info2['code']);
        $this->assertEquals('wildcard_handler', $info2['handler']);
        $this->assertEquals(['path' => ''], $info2['params']);

        $this->assertEquals(200, $info3['code']);
        $this->assertEquals('static_handler', $info3['handler']);
        $this->assertEquals(['id' => '123'], $info3['params']);

        $this->assertEquals(200, $info5['code']);
        $this->assertEquals('wildcard_handler', $info5['handler']);
        $this->assertEquals(['path' => 'anything/else'], $info5['params']);

        $this->assertEquals(200, $info6['code']);
        $this->assertEquals('wildcard_handler', $info6['handler']);
        $this->assertEquals(['path' => 'download/123/456'], $info6['params']);
    }

    public function testWildcardAndParameterMixing()
    {
        $router = new RadixRouter();
        // Wildcard at the end, with a parameter before
        $router->add('GET', '/foo/:bar/:rest*', 'handler1');
        // Wildcard at the end, with static before
        $router->add('GET', '/static/:rest*', 'handler2');
        // Wildcard with nothing before
        $router->add('GET', '/:rest*', 'handler3');

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

    public function testOptionalOnlyAtEnd()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '/foo/:bar?/baz', 'bad_handler');
    }

    public function testEmptyPatternThrows()
    {
        $router = new RadixRouter();
        $this->expectException(\InvalidArgumentException::class);
        $router->add('GET', '', 'root_handler');
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

    public function testLookupWithTrailingSlash()
    {
        $router = new RadixRouter();
        $router->add('GET', '/test/', 'trailing_handler');
        $info = $router->lookup('GET', '/test');
        $this->assertEquals(200, $info['code']);
        $this->assertEquals('trailing_handler', $info['handler']);
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
}
