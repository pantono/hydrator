<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use DateTimeInterface;
use Pantono\Contracts\Attributes\EagerLoad;

#[EagerLoad]
class DateTimeEagerModel
{
    private \DateTimeImmutable $date;

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }
}
