<?php

use Wilaak\Http\RadixRouter;

class BacktrackingTest extends RadixRouterTestCase
{
    public function testFallsBackFromStaticBranchToParameterBranch()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/static/:y/end', 'end');
        $router->add('GET', '/a/:x/other', 'other');

        $info = $router->lookup('GET', '/a/static/1/end');
        $this->assertEquals('end', $info['handler']);
        $this->assertEquals(['y' => '1'], $info['params']);

        $info = $router->lookup('GET', '/a/static/other');
        $this->assertEquals('other', $info['handler']);
        $this->assertEquals(['x' => 'static'], $info['params']);

        $this->assertEquals(404, $router->lookup('GET', '/a/static/nope')['code']);
    }

    public function testStaticBranchStillWinsWhenBothMatch()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/static/:y', 'static_branch');
        $router->add('GET', '/a/:x/other', 'param_branch');

        $info = $router->lookup('GET', '/a/static/other');
        $this->assertEquals('static_branch', $info['handler']);
        $this->assertEquals(['y' => 'other'], $info['params']);
    }

    public function testBacktracksAcrossSeveralLevels()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/static/:y', 'sy');
        $router->add('GET', '/a/:x/other/:z', 'oz');

        $info = $router->lookup('GET', '/a/static/other/5');
        $this->assertEquals('oz', $info['handler']);
        $this->assertEquals(['x' => 'static', 'z' => '5'], $info['params']);
    }

    public function testBacktracksThroughNestedShadowing()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/s1/s2/:y/deep', 'deep');
        $router->add('GET', '/a/s1/:p/end', 'mid');
        $router->add('GET', '/a/:x/s2/end', 'outer');

        $info = $router->lookup('GET', '/a/s1/s2/end');
        $this->assertEquals('mid', $info['handler']);
        $this->assertEquals(['p' => 's2'], $info['params']);

        $router = new RadixRouter();
        $router->add('GET', '/a/s1/s2/:y/deep', 'deep');
        $router->add('GET', '/a/:x/s2/end', 'outer');

        $info = $router->lookup('GET', '/a/s1/s2/end');
        $this->assertEquals('outer', $info['handler']);
        $this->assertEquals(['x' => 's1'], $info['params']);
    }

    public function testOuterChoiceStillReachableAfterInnerFails()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/s1/s2/:y/deep', 'inner_static');
        $router->add('GET', '/a/s1/:p/q', 'inner_param');
        $router->add('GET', '/a/:x/s2/end', 'outer');

        $info = $router->lookup('GET', '/a/s1/s2/end');
        $this->assertEquals('outer', $info['handler']);
        $this->assertEquals(['x' => 's1'], $info['params']);
    }

    public function testUnwindsThroughSeveralNestedChoices()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/:x/:y/:z/:w', 'deep');
        $router->add('GET', '/a/b/c/d/x', 's');
        $router->add('GET', '/a/b/:q/d/y', 't');
        $router->add('GET', '/a/b/c/:q/z', 'u');

        $info = $router->lookup('GET', '/a/b/c/d/e');
        $this->assertEquals('deep', $info['handler']);
        $this->assertEquals(['x' => 'b', 'y' => 'c', 'z' => 'd', 'w' => 'e'], $info['params']);
        $this->assertEquals('s', $router->lookup('GET', '/a/b/c/d/x')['handler']);
        $this->assertEquals('t', $router->lookup('GET', '/a/b/c/d/y')['handler']);
        $this->assertEquals('u', $router->lookup('GET', '/a/b/c/d/z')['handler']);
    }

    public function testReadmeAvatarExample()
    {
        $router = new RadixRouter();
        $router->add('GET', '/users/:id/avatar', 'user_avatar');
        $router->add('GET', '/users/me/profile/:tab', 'my_profile');

        $info = $router->lookup('GET', '/users/me/avatar');
        $this->assertEquals('user_avatar', $info['handler']);
        $this->assertEquals(['id' => 'me'], $info['params']);
    }

    public function testParameterRouteBeatsWildcardAfterBacktrack()
    {
        // Exact routes win over wildcards even when the wildcard sits on the
        // static branch that was tried first.
        $router = new RadixRouter();
        $router->add('GET', '/a/static/:rest*', 'wild');
        $router->add('GET', '/a/:x/:y/:z', 'params');

        $info = $router->lookup('GET', '/a/static/b/c');
        $this->assertEquals('params', $info['handler']);
        $this->assertEquals(['x' => 'static', 'y' => 'b', 'z' => 'c'], $info['params']);

        $info = $router->lookup('GET', '/a/static/b/c/d');
        $this->assertEquals('wild', $info['handler']);
        $this->assertEquals(['rest' => 'b/c/d'], $info['params']);
    }

    public function testMethodMismatchAfterBacktrackIs405()
    {
        $router = new RadixRouter();
        $router->add('GET', '/a/static/:y/end', 'end');
        $router->add('POST', '/a/:x/other', 'other');

        $info = $router->lookup('GET', '/a/static/other');
        $this->assertEquals(405, $info['code']);
        $this->assertEquals(['POST'], $info['allowed_methods']);
    }

    public function testMethodMissOnStaticBranchContinuesToParameterBranch()
    {
        $router = new RadixRouter();
        $router->add('POST', '/a/static/:y', 'post_static');
        $router->add('GET', '/a/:x/other', 'get_param');

        $info = $router->lookup('GET', '/a/static/other');
        $this->assertEquals('get_param', $info['handler']);
        $this->assertEquals(['x' => 'static'], $info['params']);

        $info = $router->lookup('POST', '/a/static/other');
        $this->assertEquals('post_static', $info['handler']);
    }

    public function testMethodMissInStaticTableContinuesToTree()
    {
        $router = new RadixRouter();
        $router->add('POST', '/users/me/posts', 'create');
        $router->add('GET', '/users/:id/posts', 'posts');

        $info = $router->lookup('GET', '/users/me/posts');
        $this->assertEquals('posts', $info['handler']);
        $this->assertEquals(['id' => 'me'], $info['params']);

        $info = $router->lookup('DELETE', '/users/me/posts');
        $this->assertEquals(405, $info['code']);
        $this->assertEqualsCanonicalizing(['POST', 'GET', 'HEAD'], $info['allowed_methods']);
        $this->assertEqualsCanonicalizing(['POST', 'GET', 'HEAD'], $router->methods('/users/me/posts'));
        $this->assertEqualsCanonicalizing(['/users/me/posts', '/users/:id/posts'], array_column($router->list('/users/me/posts'), 'pattern'));
    }

    public function testAllowedMethodsIsUnionOfAllMatchingRoutes()
    {
        $router = new RadixRouter();
        $router->add('POST', '/a/static/:y', 'a');
        $router->add('PUT', '/a/:x/other', 'b');
        $router->add('PATCH', '/a/:x/:z', 'c');

        $info = $router->lookup('GET', '/a/static/other');
        $this->assertEquals(405, $info['code']);
        $this->assertEqualsCanonicalizing(['POST', 'PUT', 'PATCH'], $info['allowed_methods']);
    }

    public function testMethodMissOnDeepWildcardContinuesToShallowerWildcard()
    {
        $router = new RadixRouter();
        $router->add('GET', '/files/x/:deep*', 'deep');
        $router->add('POST', '/files/:all*', 'shallow');

        $info = $router->lookup('POST', '/files/x/y/z');
        $this->assertEquals('shallow', $info['handler']);
        $this->assertEquals(['all' => 'x/y/z'], $info['params']);

        $info = $router->lookup('GET', '/files/x/y/z');
        $this->assertEquals('deep', $info['handler']);

        $info = $router->lookup('PUT', '/files/x/y/z');
        $this->assertEquals(405, $info['code']);
        $this->assertEqualsCanonicalizing(['GET', 'HEAD', 'POST'], $info['allowed_methods']);
    }
}
