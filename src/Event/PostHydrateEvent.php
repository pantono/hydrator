<?php

namespace Pantono\Hydrator\Event;

use Symfony\Contracts\EventDispatcher\Event;

class PostHydrateEvent extends Event
{
    private string $className;
    /**
     * @var array<int,mixed>
     */
    private array $hydrateData;
    private ?object $result;

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int,mixed> $hydrateData
     * @param T|null $result
     */
    public function __construct(string $className, array $hydrateData, ?object $result = null)
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

    /**
     * @return object
     */
    public function getResult(): object
    {
        if ($this->result === null) {
            throw new \RuntimeException('Result must be set before calling getResult()');
        }
        return $this->result;
    }

    public function setResult(object $result): void
    {
        $this->result = $result;
    }
}
