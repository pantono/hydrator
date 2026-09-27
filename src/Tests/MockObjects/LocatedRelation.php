<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\Locator;

#[DatabaseTable(table: 'located', idColumn: 'id'), Locator(serviceName: 'Custom', methodName: 'find')]
class LocatedRelation
{
    private int $id = 0;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

}
