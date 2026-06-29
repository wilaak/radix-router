<?php

use Wilaak\Http\RadixRouter;

/**
 * Pins down how the router treats unusual request paths at lookup time:
 * empty segments, percent-encoding, unicode, and verbatim wildcard
 * capture. The router does NOT decode or normalize path segments beyond
 * stripping a single trailing slash. If any of these change, downstream
 * consumers (URL decoders, normalizers) will start receiving different
 * values, which is a backwards-incompatible change.
 */
class LookupPathQuirksTest extends RadixRouterTestCase
{
    public function testEmptyInteriorSegmentDoesNotMatch()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/bar', 'h');

        $info = $router->lookup('GET', '/foo//bar');
        $this->assertSame(404, $info['code']);
    }

    public function testEmptyInteriorSegmentDoesNotFillParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar/baz', 'h');

        $info = $router->lookup('GET', '/foo//baz');
        $this->assertSame(404, $info['code']);
    }

    public function testPercentEncodedSegmentIsReturnedRaw()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/:name', 'h');

        // Router does not URL-decode. Callers are responsible for that.
        $info = $router->lookup('GET', '/users/john%20doe');
        $this->assertSame(200, $info['code']);
        $this->assertSame(['name' => 'john%20doe'], $info['params']);
    }

    public function testUnicodeSegmentIsReturnedRaw()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/:name', 'h');

        $info = $router->lookup('GET', '/users/café');
        $this->assertSame(200, $info['code']);
        $this->assertSame(['name' => 'café'], $info['params']);
    }

    public function testRootLevelRequiredParamDoesNotMatchRootPath()
    {
        // Regression: a required param `:a` must not match `/` (or '') with an
        // empty value. Required params reject empty segments everywhere else,
        // and the root path has no segment to bind.
        $router = new RadixRouter();
        $router->add('GET', '/:a', 'h');

        $this->assertSame(404, $router->lookup('GET', '/')['code']);
        $this->assertSame(404, $router->lookup('GET', '')['code']);

        // A real segment still matches.
        $info = $router->lookup('GET', '/foo');
        $this->assertSame(200, $info['code']);
        $this->assertSame(['a' => 'foo'], $info['params']);
    }

    public function testRootLevelRequiredWildcardDoesNotMatchRootPath()
    {
        // The `+` wildcard requires at least one segment, so `/` must 404.
        $router = new RadixRouter();
        $router->add('GET', '/:rest+', 'h');

        $this->assertSame(404, $router->lookup('GET', '/')['code']);
        $this->assertSame(['rest' => 'a/b'], $router->lookup('GET', '/a/b')['params']);
    }

    public function testStaticRootStillWinsAlongsideRootParam()
    {
        $router = new RadixRouter();
        $router->add('GET', '/', 'root');
        $router->add('GET', '/:a', 'param');

        $root = $router->lookup('GET', '/');
        $this->assertSame(200, $root['code']);
        $this->assertSame('root', $root['handler']);
    }

    public function testWildcardCapturesRemainderVerbatimIncludingSlashesAndColons()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'h');

        $info = $router->lookup('GET', '/files/a/b.c/:fake/d%2Fe');
        $this->assertSame(200, $info['code']);
        $this->assertSame(['path' => 'a/b.c/:fake/d%2Fe'], $info['params']);
    }
}
