<?php

namespace Pantono\Hydrator\Event;

use Symfony\Contracts\EventDispatcher\Event;

class PreHydrateSetEvent extends Event
{
    private string $className;
    /**
     * @var array<int,mixed>
     */
    private array $hydrateData;

    /**
     * @param class-string $className
     * @param array<int,mixed> $hydrateData
     */
    public function __construct(string $className, array $hydrateData)
    {
        $this->className = $className;
        $this->hydrateData = $hydrateData;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getHydrateData(): array
    {
        return $this->hydrateData;
    }

    /**
     * @param array<int,mixed> $hydrateData
     */
    public function setHydrateData(array $hydrateData): void
    {
        $this->hydrateData = $hydrateData;
    }
}
