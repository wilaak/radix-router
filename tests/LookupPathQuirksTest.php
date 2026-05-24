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

        // /foo//bar explodes into ['foo', '', 'bar']. The empty segment
        // cannot satisfy a static or parameter node, so the lookup 404s.
        $info = $router->lookup('GET', '/foo//bar');
        $this->assertSame(404, $info['code']);
    }

    public function testEmptyInteriorSegmentDoesNotFillParameter()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar/baz', 'h');

        // :bar must consume a non-empty segment.
        $info = $router->lookup('GET', '/foo//baz');
        $this->assertSame(404, $info['code']);
    }

    public function testPercentEncodedSegmentIsReturnedRaw()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/:name', 'h');

        $info = $router->lookup('GET', '/users/john%20doe');
        $this->assertSame(200, $info['code']);
        // Router does not URL-decode. Callers are responsible for that.
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

    public function testWildcardCapturesRemainderVerbatimIncludingSlashesAndColons()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/:path*', 'h');

        // Slashes, dots, encoded chars, and even colon-prefixed segments
        // that look like parameters must be returned exactly as they
        // appeared in the request path.
        $info = $router->lookup('GET', '/files/a/b.c/:fake/d%2Fe');
        $this->assertSame(200, $info['code']);
        $this->assertSame(['path' => 'a/b.c/:fake/d%2Fe'], $info['params']);
    }
}
