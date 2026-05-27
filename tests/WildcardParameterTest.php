<?php

use Wilaak\Http\RadixRouter;

class WildcardParameterTest extends RadixRouterTestCase
{
    public function testOptionalWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/download/:id', 'static_handler');
        $router->add('GET', '/files/:path*', 'wildcard_handler');

        $info1 = $router->lookup('GET', '/files');
        $info2 = $router->lookup('GET', '/files/');
        $info3 = $router->lookup('GET', '/files/download/123');
        $info4 = $router->lookup('GET', '/files/anything/else');
        $info5 = $router->lookup('GET', '/files/download/123/456');

        $this->assertEquals('wildcard_handler', $info1['handler']);
        $this->assertEquals(['path' => ''], $info1['params']);

        $this->assertEquals('wildcard_handler', $info2['handler']);
        $this->assertEquals(['path' => ''], $info2['params']);

        $this->assertEquals('static_handler', $info3['handler']);
        $this->assertEquals(['id' => '123'], $info3['params']);

        $this->assertEquals('wildcard_handler', $info4['handler']);
        $this->assertEquals(['path' => 'anything/else'], $info4['params']);

        $this->assertEquals('wildcard_handler', $info5['handler']);
        $this->assertEquals(['path' => 'download/123/456'], $info5['params']);
    }

    public function testRequiredWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'wildcard_handler');
        $router->add('GET', '/files/required/:path+', 'required_wildcard_handler');

        $info1 = $router->lookup('GET', '/files/required/a');
        $info2 = $router->lookup('GET', '/files/required/a/b/c');
        $info3 = $router->lookup('GET', '/files/required');

        $this->assertEquals('required_wildcard_handler', $info1['handler']);
        $this->assertEquals(['path' => 'a'], $info1['params']);

        $this->assertEquals('required_wildcard_handler', $info2['handler']);
        $this->assertEquals(['path' => 'a/b/c'], $info2['params']);

        $this->assertEquals('wildcard_handler', $info3['handler']);
    }

    public function testWildcardWithNoSegmentsCapturesEmptyString()
    {
        $router = new RadixRouter();
        $router->add('GET', '/wild/:rest*', 'handler');
        $info = $router->lookup('GET', '/wild');
        $this->assertEquals(['rest' => ''], $info['params']);
    }

    public function testWildcardAfterParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar/:rest*', 'handler1');

        $info = $router->lookup('GET', '/foo/abc/def/ghi');
        $this->assertEquals('handler1', $info['handler']);
        $this->assertEquals(['bar' => 'abc', 'rest' => 'def/ghi'], $info['params']);

        $info = $router->lookup('GET', '/foo/abc');
        $this->assertEquals('handler1', $info['handler']);
        $this->assertEquals(['bar' => 'abc', 'rest' => ''], $info['params']);
    }

    public function testRequiredWildcardAfterParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/bar/:id/:rest+', 'specific');
        $router->add('GET', '/:rest*', 'fallback');

        $info = $router->lookup('GET', '/bar/123/abc');
        $this->assertEquals('specific', $info['handler']);
        $this->assertEquals(['id' => '123', 'rest' => 'abc'], $info['params']);

        $info = $router->lookup('GET', '/bar/123/abc/def');
        $this->assertEquals('specific', $info['handler']);
        $this->assertEquals(['id' => '123', 'rest' => 'abc/def'], $info['params']);

        $info = $router->lookup('GET', '/bar/123');
        $this->assertEquals('fallback', $info['handler']);
    }

    public function testRootLevelWildcardCatchAll()
    {
        $router = new RadixRouter();
        $router->add('GET', '/static/:rest*', 'handler2');
        $router->add('GET', '/:rest*', 'handler3');
        $router->add('GET', '/required/:rest+', 'handler4');

        $info = $router->lookup('GET', '/static/one/two/three');
        $this->assertEquals('handler2', $info['handler']);
        $this->assertEquals(['rest' => 'one/two/three'], $info['params']);

        $info = $router->lookup('GET', '/static/');
        $this->assertEquals('handler2', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);

        $info = $router->lookup('GET', '/anything/else');
        $this->assertEquals('handler3', $info['handler']);
        $this->assertEquals(['rest' => 'anything/else'], $info['params']);

        $info = $router->lookup('GET', '/');
        $this->assertEquals('handler3', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);

        $info = $router->lookup('GET', '/required/foo');
        $this->assertEquals('handler4', $info['handler']);
        $this->assertEquals(['rest' => 'foo'], $info['params']);

        $info = $router->lookup('GET', '/required/foo/bar/baz');
        $this->assertEquals('handler4', $info['handler']);
        $this->assertEquals(['rest' => 'foo/bar/baz'], $info['params']);

        $info = $router->lookup('GET', '/required');
        $this->assertEquals('handler3', $info['handler']);
    }

    public function testWildcardFallbackChain()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/bar', 'static_handler');
        $router->add('GET', '/foo/:param', 'param_handler');
        $router->add('GET', '/foo/:rest*', 'wildcard_handler');

        $info = $router->lookup('GET', '/foo/bar');
        $this->assertEquals('static_handler', $info['handler']);

        $info = $router->lookup('GET', '/foo/baz');
        $this->assertEquals('param_handler', $info['handler']);
        $this->assertEquals(['param' => 'baz'], $info['params']);

        $info = $router->lookup('GET', '/foo/bar/baz/qux');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => 'bar/baz/qux'], $info['params']);

        $info = $router->lookup('GET', '/foo');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['rest' => ''], $info['params']);
    }

    public function testWildcardFallbackWithOverlappingDynamicRoutes()
    {
        $router = new RadixRouter();
        $router->add('GET', '/api/:version/users/:id', 'user_handler');
        $router->add('GET', '/api/:version/:rest*', 'wildcard_handler');

        $info = $router->lookup('GET', '/api/v1/users/42');
        $this->assertEquals('user_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'id' => '42'], $info['params']);

        $info = $router->lookup('GET', '/api/v1/other/path');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'rest' => 'other/path'], $info['params']);

        $info = $router->lookup('GET', '/api/v2');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v2', 'rest' => ''], $info['params']);
    }

    public function testFallbackWildcardDoesNotLeakDynamicParams()
    {
        $router = new RadixRouter();
        $router->add('GET', '/api/:version/users/:id', 'user_handler');
        $router->add('GET', '/api/:version/:rest*', 'wildcard_handler');

        $info = $router->lookup('GET', '/api/v1/users/42/extra');
        $this->assertEquals('wildcard_handler', $info['handler']);
        $this->assertEquals(['version' => 'v1', 'rest' => 'users/42/extra'], $info['params']);
        $this->assertArrayNotHasKey('id', $info['params']);
    }

    public function testOptionalWildcardPrioritizationByMethod()
    {
        $router = new RadixRouter();

        $router->add('DELETE', '/:test+', 'required');
        $router->add('POST',   '/:test*', 'optional');
        $router->add('GET',    '/demo/:test+', 'demo_required');

        $this->assertEquals(['POST'], $router->lookup('DELETE', '/')['allowed_methods']);

        $this->assertEquals(['DELETE', 'POST'], $router->lookup('GET', '/demo')['allowed_methods']);

        $this->assertEquals('optional', $router->lookup('POST', '/demo')['handler']);
        $this->assertEquals('required', $router->lookup('DELETE', '/demo')['handler']);
    }

    // The :name? route expands into static /foo + parametric /foo/:name,
    // while :rest* lives on a separate wildcard child of /foo. Routing
    // commits to the first matching node (static or param) and only
    // falls back to the wildcard when no node matches at all, so POST is
    // only reachable at depths the :name? expansion does not cover.
    public function testOptionalParameterAndWildcardAtSamePrefixDifferentMethods()
    {
        $router = new RadixRouter();
        $router->add('GET',  '/foo/:name?', 'g');
        $router->add('POST', '/foo/:rest*', 'p');

        $this->assertSame('g',  $router->lookup('GET',  '/foo')['handler']);
        $this->assertSame([],   $router->lookup('GET',  '/foo')['params']);
        $this->assertSame(405,  $router->lookup('POST', '/foo')['code']);

        $this->assertSame('g', $router->lookup('GET',  '/foo/abc')['handler']);
        $this->assertSame(405, $router->lookup('POST', '/foo/abc')['code']);

        $this->assertSame(405, $router->lookup('GET',  '/foo/a/b/c')['code']);
        $this->assertSame('p', $router->lookup('POST', '/foo/a/b/c')['handler']);
        $this->assertSame(['rest' => 'a/b/c'], $router->lookup('POST', '/foo/a/b/c')['params']);
    }

    public function testOptionalParameterFollowedByWildcardIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid Pattern: [GET] '/foo/:opt?/:rest*': Optional parameters are only allowed in the last trailing segments");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:opt?/:rest*', 'h');
    }

    public function testManualVariantsAreEquivalentToOptionalPlusWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:rest*',      'no_opt');
        $router->add('GET', '/foo/:opt/:rest*', 'with_opt');

        $info = $router->lookup('GET', '/foo');
        $this->assertSame('no_opt', $info['handler']);
        $this->assertSame(['rest' => ''], $info['params']);

        $info = $router->lookup('GET', '/foo/abc');
        $this->assertSame('with_opt', $info['handler']);
        $this->assertSame(['opt' => 'abc', 'rest' => ''], $info['params']);

        $info = $router->lookup('GET', '/foo/abc/x/y');
        $this->assertSame('with_opt', $info['handler']);
        $this->assertSame(['opt' => 'abc', 'rest' => 'x/y'], $info['params']);
    }

    public function testOptionalWildcardListingDeduplicatesAtRoot()
    {
        $router = new RadixRouter();
        $router->add('GET',  '/:test*', 'optional');
        $router->add('POST', '/:test+', 'required');

        $routes = $router->list('/');
        $this->assertCount(1, $routes);
    }

    public function testOptionalWildcardWithTrailingSlashInPatternMatchesEmpty()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:param*/', 'handler');

        $info = $router->lookup('GET', '/');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['param' => ''], $info['params']);

        $info = $router->lookup('GET', '/abc');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['param' => 'abc'], $info['params']);

        $info = $router->lookup('GET', '/abc/def');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['param' => 'abc/def'], $info['params']);
    }

    public function testWildcardPatternMatchesRequestEndingInSlash()
    {
        $router = new RadixRouter();
        $router->add('GET',    '/files/:path*', 'optional');
        $router->add('DELETE', '/files/:path+', 'required');

        $info = $router->lookup('GET', '/files/');
        $this->assertSame('optional', $info['handler']);
        $this->assertSame(['path' => ''], $info['params']);

        $info = $router->lookup('DELETE', '/files/');
        $this->assertSame(405, $info['code']);
        $this->assertSame(['GET', 'HEAD'], $info['allowed_methods']);
    }

    public function testRequiredWildcardWithTrailingSlashInPatternDoesNotMatchEmpty()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:param+/', 'handler');

        $info = $router->lookup('GET', '/');
        $this->assertSame(404, $info['code']);

        $info = $router->lookup('GET', '/abc');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['param' => 'abc'], $info['params']);

        $info = $router->lookup('GET', '/abc/def');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['param' => 'abc/def'], $info['params']);
    }

    public function testWildcardCapturesUrlEncodedBytesVerbatim()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'handler');

        $info = $router->lookup('GET', '/files/hello%2Fworld%20space');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['path' => 'hello%2Fworld%20space'], $info['params']);
    }

    public function testWildcardCapturesConsecutiveSlashesInRequestPath()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'handler');

        $info = $router->lookup('GET', '/files//a//b');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['path' => '/a//b'], $info['params']);
    }

    public function testWildcardCapturesDotSegmentsVerbatim()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'handler');

        $info = $router->lookup('GET', '/files/../etc');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['path' => '../etc'], $info['params']);

        $info = $router->lookup('GET', '/files/./a/./b');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['path' => './a/./b'], $info['params']);
    }

    public function testHeadFallsBackToGetThroughWildcardBranch()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:rest*', 'handler');

        $info = $router->lookup('HEAD', '/anything/here');
        $this->assertSame('handler', $info['handler']);
        $this->assertSame(['rest' => 'anything/here'], $info['params']);
    }

    public function testWildcardPatternsDifferingOnlyByTrailingSlashConflict()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:rest*', 'first');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/:rest*/': Path is already registered (conflicts with '/:rest*')");
        $router->add('GET', '/:rest*/', 'second');
    }

    public function testRequiredWildcardAloneCannotMatchZeroSegments()
    {
        $router = new RadixRouter();
        $router->add('GET', '/:rest+', 'handler');

        $info = $router->lookup('GET', '/');
        $this->assertSame(404, $info['code']);
    }

    public function testWildcard405MethodListExcludesUnsatisfiableRequired()
    {
        $router = new RadixRouter();
        $router->add('GET',  '/:rest*', 'optional');
        $router->add('POST', '/:rest+', 'required');

        $info = $router->lookup('DELETE', '/');
        $this->assertSame(405, $info['code']);
        $this->assertSame(['GET', 'HEAD'], $info['allowed_methods']);
    }
}
