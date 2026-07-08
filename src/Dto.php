<?php

namespace Pantono\Hydrator;

use Pantono\Contracts\Locator\LocatorInterface;
use Pantono\Hydrator\Dto\AbstractDto;

class Dto
{
    private LocatorInterface $locator;

    public function __construct(LocatorInterface $locator)
    {
        $this->locator = $locator;
    }

    public function createDto(AbstractDto $dtoClass): AbstractDto
    {
        /**
         * @var AbstractDto $dto
         */
        $dto = $this->locator->getClassAutoWire($dtoClass::class);
        $dto->setLocator($this->locator);
        return $dto;
    }
}
