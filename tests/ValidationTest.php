<?php

use Wilaak\Http\RadixRouter;

/**
 * Registration-time validation. Malformed patterns, conflicting routes,
 * invalid parameter names, and invalid HTTP methods must all throw
 * InvalidArgumentException at add() time, never silently.
 */
class ValidationTest extends RadixRouterTestCase
{
    // Route conflicts

    public function testDuplicateStaticRouteThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/conflict', 'handler1');
        $router->add('GET', '/conflict', 'handler2');
    }

    public function testTwoParameterRoutesAtSamePositionThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:id',  'handler1');
        $router->add('GET', '/foo/:asd', 'handler2');
    }

    public function testTwoWildcardCardinalitiesAtSamePositionThrow()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/bar/:rest*', 'handler1');
        $router->add('GET', '/bar/:rest+', 'handler2');
    }

    // Pattern shape

    public function testWildcardMustBeLastSegment()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar*/baz', 'bad_handler');
    }

    public function testRequiredWildcardMustBeLastSegment()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar+/baz', 'bad_handler');
    }

    public function testOptionalParameterMustBeTrailing()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar?/baz', 'bad_handler');
    }

    public function testCannotCombineWildcardAndOptionalMarker()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar*?', 'bad_handler');
    }

    public function testCannotCombineRequiredWildcardAndOptionalMarker()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:bar+?', 'bad_handler');
    }

    public function testDuplicateParameterNameInPatternThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '/foo/:id/bar/:id', 'handler');
    }

    public function testDoubleSlashInPatternThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('GET', '//foo', 'handler');
    }

    // Methods

    public function testUnknownHttpMethodThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('FOOBAR', '/bad', 'handler');
    }

    public function testEmptyMethodStringThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add('', '/bad', 'handler');
    }

    public function testEmptyMethodArrayThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $router = new RadixRouter();
        $router->add([], '/bad', 'handler');
    }

    // Parameter names

    public function testInvalidParameterNamesThrow()
    {
        $invalidPatterns = [
            'empty name'        => '/foo/:',
            'starts with digit' => '/foo/:1bar',
            'contains dash'     => '/foo/:bar-baz',
            'contains space'    => '/foo/:bar baz',
            'special character' => '/foo/:bar$',
        ];

        foreach ($invalidPatterns as $label => $pattern) {
            $router = new RadixRouter();
            try {
                $router->add('GET', $pattern, 'handler');
                $this->fail("Pattern '$pattern' ($label) should have thrown");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true, $label);
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
}
