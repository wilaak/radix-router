<?php

use PHPUnit\Framework\TestCase;

abstract class RadixRouterTestCase extends TestCase
{
    /**
     * Representative sample of every supported pattern shape. Several test
     * classes iterate over this list to verify that a behavior holds
     * uniformly across all pattern types.
     */
    protected static function patternTypes(): array
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
}
