<?php

namespace Pantono\Hydrator\Dto;

use Pantono\Contracts\Locator\LocatorInterface;
use Symfony\Component\HttpFoundation\ParameterBag;

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

    abstract public function transform(ParameterBag $parameters): mixed;
}
