<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\Lazy;
use Pantono\Contracts\Attributes\Database\OneToOne;

#[DatabaseTable(table: 'plain_children', idColumn: 'id')]
class PlainRelationChild
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

    private int $parentId = 0;

    public function getParentId(): int
    {
        return $this->parentId;
    }

    public function setParentId(int $parentId): void
    {
        $this->parentId = $parentId;
    }

    #[OneToOne(targetModel: RelationNode::class), Lazy]
    private ?RelationNode $node = null;

    public function getNode(): ?RelationNode
    {
        return $this->node;
    }

    public function setNode(?RelationNode $node): void
    {
        $this->node = $node;
    }

}
