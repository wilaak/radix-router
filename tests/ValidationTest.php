<?php

use Wilaak\Http\RadixRouter;

/**
 * Registration-time validation. Malformed patterns, conflicting routes,
 * invalid parameter names, and invalid HTTP methods must all throw
 * InvalidArgumentException at add() time, never silently.
 */
class ValidationTest extends RadixRouterTestCase
{
    public function testDuplicateStaticRouteThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/conflict': Path is already registered");
        $router = new RadixRouter();
        $router->add('GET', '/conflict', 'handler1');
        $router->add('GET', '/conflict', 'handler2');
    }

    public function testTwoParameterRoutesAtSamePositionThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/foo/:asd': Path is already registered (conflicts with '/foo/:id')");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:id',  'handler1');
        $router->add('GET', '/foo/:asd', 'handler2');
    }

    public function testTwoWildcardCardinalitiesAtSamePositionThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/bar/:rest+': Path is already registered (conflicts with '/bar/:rest*')");
        $router = new RadixRouter();
        $router->add('GET', '/bar/:rest*', 'handler1');
        $router->add('GET', '/bar/:rest+', 'handler2');
    }

    public function testWildcardMustBeLastSegment()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid Pattern: [GET] '/foo/:bar*/baz': Wildcard parameters are only allowed as the last segment");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar*/baz', 'bad_handler');
    }

    public function testRequiredWildcardMustBeLastSegment()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid Pattern: [GET] '/foo/:bar+/baz': Wildcard parameters are only allowed as the last segment");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar+/baz', 'bad_handler');
    }

    public function testOptionalParameterMustBeTrailing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid Pattern: [GET] '/foo/:bar?/baz': Optional parameters are only allowed in the last trailing segments");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar?/baz', 'bad_handler');
    }

    // ':bar*?' parses as suffix '?' (optional marker) with name 'bar*'.
    // The name regex then rejects '*' as an invalid name character.
    public function testCannotCombineWildcardAndOptionalMarker()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter name 'bar*' must start with a letter or underscore");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar*?', 'bad_handler');
    }

    public function testCannotCombineRequiredWildcardAndOptionalMarker()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter name 'bar+' must start with a letter or underscore");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar+?', 'bad_handler');
    }

    public function testDuplicateParameterNameInPatternThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter name 'id' cannot be used more than once");
        $router = new RadixRouter();
        $router->add('GET', '/foo/:id/bar/:id', 'handler');
    }

    public function testDoubleSlashInPatternThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid Pattern: [GET] '//foo': Empty segments are not allowed (e.g., '//')");
        $router = new RadixRouter();
        $router->add('GET', '//foo', 'handler');
    }

    public function testUnknownHttpMethodThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid HTTP Method: [FOOBAR] '/bad': Allowed methods:");
        $router = new RadixRouter();
        $router->add('FOOBAR', '/bad', 'handler');
    }

    public function testEmptyMethodStringThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid HTTP Method: [] '/bad': Allowed methods:");
        $router = new RadixRouter();
        $router->add('', '/bad', 'handler');
    }

    public function testEmptyMethodArrayThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid HTTP Method: Got empty array for pattern '/bad'");
        $router = new RadixRouter();
        $router->add([], '/bad', 'handler');
    }

    public function testInvalidParameterNamesThrow()
    {
        $invalidPatterns = [
            'empty name'        => ['/foo/:',         ''],
            'starts with digit' => ['/foo/:1bar',     '1bar'],
            'contains dash'     => ['/foo/:bar-baz',  'bar-baz'],
            'contains space'    => ['/foo/:bar baz',  'bar baz'],
            'special character' => ['/foo/:bar$',     'bar$'],
        ];

        foreach ($invalidPatterns as $label => [$pattern, $expectedName]) {
            $router = new RadixRouter();
            try {
                $router->add('GET', $pattern, 'handler');
                $this->fail("Pattern '$pattern' ($label) should have thrown");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString(
                    "Parameter name '{$expectedName}' must start with a letter or underscore",
                    $e->getMessage(),
                    $label
                );
            }
        }
    }

    public function testValidParameterNamesMatch()
    {
        $validPatterns = [
            'leading underscore'    => ['/foo/:_bar',     '/foo/value', ['_bar' => 'value']],
            'trailing digits'       => ['/foo/:bar123',   '/foo/val',   ['bar123' => 'val']],
            'underscore and digits' => ['/foo/:_bar_123', '/foo/val',   ['_bar_123' => 'val']],
            'mixed case'            => ['/foo/:BarBaz',   '/foo/val',   ['BarBaz' => 'val']],
        ];

        foreach ($validPatterns as $label => [$pattern, $lookupPath, $expectedParams]) {
            $router = new RadixRouter();
            $router->add('GET', $pattern, 'handler');
            $info = $router->lookup('GET', $lookupPath);
            $this->assertEquals($expectedParams, $info['params'], $label);
        }
    }

    public function testOptionalExpansionConflictsWithExplicitStaticRegistration()
    {
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar?', 'optional_handler');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/foo': Path is already registered (conflicts with '/foo/:bar?')");
        $router->add('GET', '/foo', 'static_handler');
    }

    public function testPublicMethodParameterNamesAreStableApi()
    {
        $router = new RadixRouter();
        $router->add(methods: 'GET', pattern: '/users/:id', handler: 'user_handler');

        $info = $router->lookup(method: 'GET', path: '/users/42');
        $this->assertSame('user_handler', $info['handler']);
        $this->assertSame(['id' => '42'], $info['params']);

        $this->assertContains('GET', $router->methods(path: '/users/42'));
        $this->assertNotEmpty($router->list(path: '/users/42'));
    }
}
