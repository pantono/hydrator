<?php

namespace Pantono\Hydrator\Model;

use Pantono\Contracts\Attributes\Locator;
use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\Lazy;
use Pantono\Contracts\Attributes\EagerLoad;

/**
 * @template T of object
 */
class PantonoReflectionModel
{
    /**
     * @var \ReflectionClass<T>
     */
    private \ReflectionClass $reflection;
    /**
     * @var PantonoReflectionProperty[]
     */
    private array $properties = [];

    /**
     * @param class-string<T> $className
     */
    public function __construct(string $className)
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class '$className' does not exist");
        }
        $this->reflection = new \ReflectionClass($className);
        foreach ($this->reflection->getProperties() as $property) {
            $this->properties[] = new PantonoReflectionProperty($property);
        }
    }

    public function getLocator(): ?array
    {
        foreach ($this->reflection->getAttributes(Locator::class) as $attribute) {
            $instance = $attribute->newInstance();
            return [
                'serviceName' => $instance->serviceName,
                'methodName' => $instance->methodName,
                'classname' => $instance->className
            ];
        }
        return null;
    }

    public function getDatabaseTable(): ?string
    {
        return $this->getAttributeValue(DatabaseTable::class, 'table');
    }

    public function getDatabaseIdColumn(): ?string
    {
        return $this->getAttributeValue(DatabaseTable::class, 'idColumn');
    }

    public function isEagerLoad(): ?bool
    {
        $properties = $this->reflection->getAttributes(EagerLoad::class);
        return !empty($properties);
    }

    /**
     * @param class-string $attribute
     * @param string $field
     * @return ?string
     */
    public function getAttributeValue(string $attribute, string $field): ?string
    {
        foreach ($this->reflection->getAttributes($attribute) as $attribute) {
            $instance = $attribute->newInstance();
            if (property_exists($instance, $field)) {
                return $instance->$field;
            }
        }
        return null;
    }

    /**
     * @return PantonoReflectionProperty[]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    public function hasLazyLookups(): bool
    {
        return array_any($this->properties, fn(PantonoReflectionProperty $property): bool => $property->isLazy());
    }

    public function isCreateProxy(): bool
    {
        return $this->isEagerLoad() || $this->hasLazyLookups();
    }
}
