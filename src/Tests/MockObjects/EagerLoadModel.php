<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Database\Attributes\OneToOne;

#[EagerLoad]
class EagerLoadModel
{
    private int $id;
    #[OneToOne(targetModel: DatabaseLookupModel::class)]
    private ?DatabaseLookupModel $lookupModel = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getLookupModel(): ?DatabaseLookupModel
    {
        return $this->lookupModel;
    }

    public function setLookupModel(?DatabaseLookupModel $lookupModel): void
    {
        $this->lookupModel = $lookupModel;
    }
}
