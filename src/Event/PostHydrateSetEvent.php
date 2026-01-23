<?php

namespace Pantono\Hydrator\Event;

use Symfony\Contracts\EventDispatcher\Event;

class PostHydrateSetEvent extends Event
{
    private string $className;
    /**
     * @var array<int,mixed>
     */
    private array $hydrateData;
    /**
     * @var array<object>
     */
    private array $result = [];

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int,mixed> $hydrateData
     * @param array<T> $result
     */
    public function __construct(string $className, array $hydrateData, array $result)
    {
        $this->className = $className;
        $this->hydrateData = $hydrateData;
        $this->result = $result;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getHydrateData(): array
    {
        return $this->hydrateData;
    }

    public function getResult(): array
    {
        return $this->result;
    }

    /**
     * @param array<object> $result
     * @return void
     */
    public function setResult(array $result): void
    {
        $this->result = $result;
    }
}
