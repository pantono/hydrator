<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\Locator;
use Pantono\Contracts\Attributes\FieldName;
use Pantono\Hydrator\Tests\MockServices\MockLookupService;

class LazyLoadModelCollection
{
    #[Locator(methodName: 'getCategoriesForType', className: MockLookupService::class), FieldName('$this')]
    private array $categories = [];

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function setCategories(array $categories): void
    {
        $this->categories = $categories;
    }
}
