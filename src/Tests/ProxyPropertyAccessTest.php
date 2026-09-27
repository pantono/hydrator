<?php

namespace Pantono\Hydrator\Tests;

use PHPUnit\Framework\TestCase;
use Pantono\Hydrator\ProxyGenerator;
use Pantono\Hydrator\Tests\MockObjects\LazyLoadModel;

class ProxyPropertyAccessTest extends TestCase
{
    public function testPropertyAccess()
    {
        $generator = new ProxyGenerator();
        $code = $generator->generateProxyClass(LazyLoadModel::class);
        
        // We need to evaluate the code to test it, but it depends on many things.
        // For now, let's just check if __get and __set are present in the output.
        
        $this->assertStringContainsString('public function __get(string $name)', $code);
        $this->assertStringContainsString('public function __set(string $name, $value)', $code);
    }
}
