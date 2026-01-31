<?php

namespace Pantono\Hydrator\Tests\MockObjects;

use DateTimeInterface;

class DateTimeModel
{
    private int $id;
    private DateTimeInterface $date;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getDate(): DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(DateTimeInterface $date): void
    {
        $this->date = $date;
    }
}
