<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\Database\OneToMany;
use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Contracts\Attributes\DatabaseTable;

#[EagerLoad, DatabaseTable(table: 'table', idColumn: 'id')]
class OneToManyObject
{
    #[OneToMany(targetModel: OneToManyTargetObject::class, mappedBy: 'other_id')]
    private array $data = [];

    public function getData(): array
    {
        return $this->data;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
