<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\EagerLoad;
use Pantono\Contracts\Attributes\Database\OneToOne;

#[DatabaseTable(table: 'lower_level', idColumn: 'id'), EagerLoad]
class DeepEagerLoadFirstLevel
{
    #[OneToOne(targetModel: DeepEagerLoadSecondLevel::class)]
    private DeepEagerLoadSecondLevel $second;

    public function getSecond(): DeepEagerLoadSecondLevel
    {
        return $this->second;
    }

    public function setSecond(DeepEagerLoadSecondLevel $second): void
    {
        $this->second = $second;
    }
}
