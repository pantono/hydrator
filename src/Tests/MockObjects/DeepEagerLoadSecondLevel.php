<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use Pantono\Contracts\Attributes\DatabaseTable;

#[DatabaseTable(table: 'linked_table', idColumn: 'id')]
class DeepEagerLoadSecondLevel
{
    private string $output;

    public function getOutput(): string
    {
        return $this->output;
    }

    public function setOutput(string $output): void
    {
        $this->output = $output;
    }
}
