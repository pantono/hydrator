<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\Database\ManyToMany;
use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\EagerLoad;

#[EagerLoad, DatabaseTable(table: 'main_table', idColumn: 'id')]
class ManyToManyObject
{
    private int $id;

    #[ManyToMany(
        joinTable: 'main_to_target',
        joinColumn: 'main_id',
        inverseJoinColumn: 'target_id',
        targetModel: ManyToManyTargetObject::class
    )]
    private array $targets = [];

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @return array<ManyToManyTargetObject>
     */
    public function getTargets(): array
    {
        return $this->targets;
    }

    /**
     * @param array<ManyToManyTargetObject> $targets
     */
    public function setTargets(array $targets): void
    {
        $this->targets = $targets;
    }
}
