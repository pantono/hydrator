<?php

namespace Pantono\Hydrator\Tests\MockServices;

use Pantono\Hydrator\Tests\MockObjects\LazyLoadModelCollection;

class MockLookupService
{
    public function getCategoriesForType(LazyLoadModelCollection $collection): array
    {
        return [['id' => 1]];
    }
}
