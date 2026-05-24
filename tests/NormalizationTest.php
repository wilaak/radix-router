<?php

use Wilaak\Http\RadixRouter;

class NormalizationTest extends RadixRouterTestCase
{
    public function testTrailingSlashIsIgnoredAcrossAllPatternTypes()
    {
        foreach (self::patternTypes() as $type) {
            $router = new RadixRouter();
            $router->add('GET', $type['pattern'], 'handler');

            $this->assertEquals(
                200,
                $router->lookup('GET', $type['lookup'] . '/')['code'],
                $type['desc'] . ': trailing slash'
            );
            $this->assertEquals(
                200,
                $router->lookup('GET', $type['lookup'])['code'],
                $type['desc'] . ': no trailing slash'
            );
        }
    }
}
