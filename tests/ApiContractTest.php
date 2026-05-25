<?php

use Wilaak\Http\RadixRouter;

/**
 * Pins down the public contract: the exact shape of 404 and 405 results,
 * the empty-router base case, and the fluent add() API. Anything here
 * that breaks is a backwards-incompatible change.
 */
class ApiContractTest extends RadixRouterTestCase
{
    public function testEmptyRouterReturns404ForAnyPath()
    {
        $router = new RadixRouter();

        $this->assertSame(['code' => 404], $router->lookup('GET', '/'));
        $this->assertSame(['code' => 404], $router->lookup('GET', '/anything'));
        $this->assertSame(['code' => 404], $router->lookup('GET', '/deep/path/here'));
    }

    public function testNotFoundResultShapeContainsOnlyCode()
    {
        $router = new RadixRouter();
        $router->add('GET', '/known', 'h');

        $this->assertSame(['code' => 404], $router->lookup('GET', '/unknown'));
    }

    public function testMethodNotAllowedShapeHasAllowedMethods()
    {
        $router = new RadixRouter();
        $router->add('GET',  '/x', 'g');
        $router->add('POST', '/x', 'p');

        $info = $router->lookup('DELETE', '/x');

        $this->assertSame(405, $info['code']);
        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'POST'], $info['allowed_methods']);
    }

    // lookup('*', ...) is the introspection path used by list() and
    // methods() and is contractually 405 with the full route table.
    public function testMethodNotAllowedShapeWhenOnlyAnyMethodRegistered()
    {
        $router = new RadixRouter();
        $router->add('*', '/x', 'h');

        $info = $router->lookup('*', '/x');

        $this->assertSame(405, $info['code']);
        $this->assertContains('*', $info['allowed_methods']);
    }

    public function testAddReturnsRouterForChaining()
    {
        $router = new RadixRouter();

        $result = $router
            ->add('GET',  '/a', 'a')
            ->add('POST', '/b', 'b')
            ->add(['PUT', 'PATCH'], '/c', 'c');

        $this->assertSame($router, $result);
        $this->assertSame(200, $router->lookup('GET',   '/a')['code']);
        $this->assertSame(200, $router->lookup('POST',  '/b')['code']);
        $this->assertSame(200, $router->lookup('PUT',   '/c')['code']);
        $this->assertSame(200, $router->lookup('PATCH', '/c')['code']);
    }

    public function testTrailingSlashInPatternIsNormalizedAtRegistrationTime()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/', 'handler');

        $this->assertSame('handler', $router->lookup('GET', '/users')['handler']);
        $this->assertSame('handler', $router->lookup('GET', '/users/')['handler']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Route Conflict: [GET] '/users': Path is already registered (conflicts with '/users/')");
        $router->add('GET', '/users', 'other');
    }
}
