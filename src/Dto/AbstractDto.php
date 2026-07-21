<?php

namespace Pantono\Hydrator\Dto;

use Pantono\Contracts\Locator\LocatorInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * @template TModel of object
 */
abstract class AbstractDto
{
    private LocatorInterface $locator;

    public function getLocator(): LocatorInterface
    {
        return $this->locator;
    }

    public function setLocator(LocatorInterface $locator): void
    {
        $this->locator = $locator;
    }

    /**
     * @param TModel|null $baseModel
     * @return TModel
     */
    abstract public function transform(ParameterBag $parameters, ?object $baseModel = null): mixed;
}
