<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;

#[DatabaseTable(table: 'linked_table', idColumn: 'id')]
class OneToManyTargetObject
{
    private int $id;
    private int $otherId;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getOtherId(): int
    {
        return $this->otherId;
    }

    public function setOtherId(int $otherId): void
    {
        $this->otherId = $otherId;
    }
}
