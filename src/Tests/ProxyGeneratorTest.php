<?php

namespace Pantono\Hydrator\Tests;

use PHPUnit\Framework\TestCase;
use Pantono\Hydrator\ProxyGenerator;
use Pantono\Hydrator\Tests\MockObjects\LazyLoadModel;

class ProxyGeneratorTest extends TestCase
{
    public function testGenerateSimpleClass()
    {
        $generator = new ProxyGenerator();
        $output = $generator->generateProxyClass(LazyLoadModel::class);

        $this->assertEquals(
            file_get_contents(__DIR__ . '/MockObjects/Expected/LazyLoadProxyExpectedResult.txt'),
            $output
        );
    }
}
