<?php

namespace Pantono\Hydrator\Model;

use Pantono\Contracts\Attributes\FieldName;
use Pantono\Utilities\StringUtilities;
use Pantono\Contracts\Attributes\Filter;
use Pantono\Contracts\Attributes\Locator;
use Pantono\Contracts\Attributes\Lazy;
use Pantono\Contracts\Attributes\NoSave;
use Pantono\Contracts\Attributes\NoFill;
use Pantono\Contracts\Attributes\DateFormat;
use Pantono\Database\Attributes\OneToMany;
use Pantono\Database\Attributes\ManyToOne;
use Pantono\Database\Attributes\OneToOne;

class PantonoReflectionProperty
{
    private \ReflectionProperty $property;

    public function __construct(\ReflectionProperty $property)
    {
        $this->property = $property;
    }

    public function getFieldName(): string
    {
        $field = $this->getAttributeValue(FieldName::class, 'name');
        if ($field) {
            return $field;
        }
        return StringUtilities::snakeCase($this->property->getName());
    }

    public function getType(): ?string
    {
        if ($this->property->getType() instanceof \ReflectionNamedType) {
            return $this->property->getType()->getName();
        }
        return null;
    }

    public function isTypeBuiltIn(): bool
    {
        return in_array($this->getType(), ['string', 'int', 'float', 'bool']);
    }

    public function isDateType(): bool
    {
        return in_array($this->getType(), ['DateTime', 'DateTimeImmutable', 'DateTimeInterface']);
    }

    public function getFilter(): ?string
    {
        return $this->getAttributeValue(Filter::class, 'filter');
    }

    public function getLocatorClassName(): ?string
    {
        return $this->getAttributeValue(Locator::class, 'className');
    }

    public function getLocatorMethodName(): ?string
    {
        return $this->getAttributeValue(Locator::class, 'methodName');
    }

    public function getLocatorService(): ?string
    {
        return $this->getAttributeValue(Locator::class, 'serviceName');
    }

    public function isLazy(): bool
    {
        $properties = $this->property->getAttributes(Lazy::class);
        return !empty($properties);
    }

    public function isNoSave(): bool
    {
        $properties = $this->property->getAttributes(NoSave::class);
        return !empty($properties);
    }

    public function isNoFill(): bool
    {
        $properties = $this->property->getAttributes(NoFill::class);
        return !empty($properties);
    }

    public function getDateFormat(): ?string
    {
        return $this->getAttributeValue(DateFormat::class, 'format');
    }

    public function getLocator(): ?array
    {
        foreach ($this->property->getAttributes() as $attribute) {
            if ($attribute->getName() === Locator::class) {
                /**
                 * @var Locator $instance
                 */
                $instance = $attribute->newInstance();
                return [
                    'serviceName' => $instance->serviceName,
                    'methodName' => $instance->methodName,
                    'className' => $instance->className
                ];
            }
        }
        return null;
    }

    public function getGetter(): string
    {
        return lcfirst(StringUtilities::camelCase('get' . ucfirst($this->property->getName())));
    }

    public function getSetter(): string
    {
        return lcfirst(StringUtilities::camelCase('set' . ucfirst($this->property->getName())));
    }

    public function getOneToManyModel(): ?string
    {
        return $this->getAttributeValue(OneToMany::class, 'targetModel');
    }

    public function getOneToManyMappedBy(): ?string
    {
        return $this->getAttributeValue(OneToMany::class, 'mappedBy');
    }

    public function getManyToOneModel(): ?string
    {
        return $this->getAttributeValue(ManyToOne::class, 'targetModel');
    }

    public function getManyToOneInversedBy(): ?string
    {
        return $this->getAttributeValue(ManyToOne::class, 'inversedBy');
    }

    public function getOneToOne(): ?string
    {
        return $this->getAttributeValue(OneToOne::class, 'targetModel');
    }

    /**
     * @param class-string $attribute
     * @param string $field
     * @return ?string
     */
    public function getAttributeValue(string $attribute, string $field): ?string
    {
        foreach ($this->property->getAttributes($attribute) as $attribute) {
            $instance = $attribute->newInstance();
            if (property_exists($instance, $field)) {
                return $instance->$field;
            }
        }
        return null;
    }

    public function getReflectionProperty(): \ReflectionProperty
    {
        return $this->property;
    }

    public function getTargetType(): ?string
    {
        $type = $this->getReflectionProperty()->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        return null;
    }
}
