<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\EagerLoad;

#[EagerLoad]
class DeepEagerLoadTopLevel
{
    private DeepEagerLoadFirstLevel $model;

    public function getModel(): DeepEagerLoadFirstLevel
    {
        return $this->model;
    }

    public function setModel(DeepEagerLoadFirstLevel $model): void
    {
        $this->model = $model;
    }
}
