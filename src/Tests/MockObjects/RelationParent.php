<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Contracts\Attributes\Lazy;
use Pantono\Contracts\Attributes\Database\OneToMany;
use Pantono\Contracts\Attributes\Database\ManyToMany;

#[EagerLoad, DatabaseTable(table: 'parents', idColumn: 'id')]
class RelationParent
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

    #[OneToMany(targetModel: RelationChild::class, mappedBy: 'parent_id')]
    private array $children = [];

    public function getChildren(): array
    {
        return $this->children;
    }

    public function setChildren(array $children): void
    {
        $this->children = $children;
    }

    #[ManyToMany(joinTable: 'parent_child', joinColumn: 'parent_id', inverseJoinColumn: 'child_id', targetModel: RelationChild::class)]
    private array $linked = [];

    public function getLinked(): array
    {
        return $this->linked;
    }

    public function setLinked(array $linked): void
    {
        $this->linked = $linked;
    }

    #[OneToMany(targetModel: PlainRelationChild::class, mappedBy: 'parent_id'), Lazy]
    private array $optionalChildren = [];

    public function getOptionalChildren(): array
    {
        return $this->optionalChildren;
    }

    public function setOptionalChildren(array $optionalChildren): void
    {
        $this->optionalChildren = $optionalChildren;
    }

}
