<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Contracts\Attributes\Database\OneToOne;

#[EagerLoad, DatabaseTable(table: 'nodes', idColumn: 'id')]
class RelationNode
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

    private string $name = '';

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    #[OneToOne(targetModel: RelationNode::class)]
    private ?RelationNode $next = null;

    public function getNext(): ?RelationNode
    {
        return $this->next;
    }

    public function setNext(?RelationNode $next): void
    {
        $this->next = $next;
    }

}
