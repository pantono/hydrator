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

    /**
     * @template TDto of AbstractDto<object>
     * @param class-string<TDto> $dtoClass
     * @return TDto
     */
    public function createDto(string $dtoClass): AbstractDto
    {
        if (!class_exists($dtoClass)) {
            throw new \InvalidArgumentException('Invalid DTO class provided');
        }
        /**
         * @var TDto $dto
         */
        $dto = $this->locator->getClassAutoWire($dtoClass);
        if (!$dto instanceof AbstractDto) {
            throw new \InvalidArgumentException('Invalid DTO class provided');
        }
        $dto->setLocator($this->locator);
        return $dto;
    }
}
