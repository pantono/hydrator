<?php

namespace Pantono\Hydrator;

use Nette\PhpGenerator\PhpNamespace;
use Pantono\Contracts\Application\Proxy\ProxyInterface;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionNamedType;
use Pantono\Hydrator\Traits\LocatorAwareTrait;
use Pantono\Utilities\Model\PantonoReflectionModel;
use Pantono\Utilities\Model\PantonoReflectionProperty;
use Pantono\Utilities\EphemeralCacheHelper;
use Pantono\Contracts\Attributes\DatabaseTable;
use Pantono\Contracts\Attributes\Database\ManyToMany as ManyToManyAttribute;

class ProxyGenerator
{
    public function generateProxyClass(string $className): string
    {
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist');
        }

        $pantonoReflection = new PantonoReflectionModel($className);

        $reflection = new \ReflectionClass($className);
        $namespace = new PhpNamespace('Pantono\Proxy');
        $class = $namespace->addClass($reflection->getShortName() . 'ProxyClass');
        if ($pantonoReflection->getDatabaseTable()) {
            if ($pantonoReflection->getDatabaseIdColumn()) {
                $class->addAttribute(DatabaseTable::class, ['table' => $pantonoReflection->getDatabaseTable(), 'idColumn' => $pantonoReflection->getDatabaseIdColumn()]);
            } else {
                $class->addAttribute(DatabaseTable::class, ['table' => $pantonoReflection->getDatabaseTable()]);
            }

        }
        $class->setExtends($className);
        $namespace->addUse(LocatorAwareTrait::class);
        $class->addTrait(LocatorAwareTrait::class);
        $namespace->addUse($className);
        $namespace->addUse(EphemeralCacheHelper::class);
        $namespace->addUse(ProxyInterface::class);
        $class->addImplement(ProxyInterface::class);

        $class->addProperty('hydratorParams')->setType('array')->setValue([])->setVisibility('private');
        $class->addProperty('completedLookups')->setType('array')->setValue([])->setVisibility('private');

        $getMagic = $class->addMethod('__get');
        $getMagic->addParameter('name')->setType('string');
        $getMagic->setBody('
$getter = \'get\' . ucfirst($name);
if (method_exists($this, $getter)) {
    return $this->$getter();
}
if (property_exists($this, $name)) {
    $rp = new \ReflectionProperty(parent::class, $name);
    try {
        return $rp->getValue($this);
    } catch (\Error $e) {
    }
}
return $this->$name;
');

        $setMagic = $class->addMethod('__set');
        $setMagic->addParameter('name')->setType('string');
        $setMagic->addParameter('value');
        $setMagic->setBody('
$setter = \'set\' . ucfirst($name);
if (method_exists($this, $setter)) {
    $this->$setter($value);
    return;
}
if (property_exists($this, $name)) {
    $rp = new \ReflectionProperty(parent::class, $name);
    $rp->setValue($this, $value);
    return;
}
$this->$name = $value;
');

        $getterMethod = $class->addMethod('setHydratorParams');
        $getterMethod->setReturnType('void');
        $getterMethod->addParameter('params')->setType('array');
        $getterMethod->setBody('$this->hydratorParams = $params;');
        $pantonoReflection = new PantonoReflectionModel($className);
        foreach ($pantonoReflection->getProperties() as $property) {
            $fieldName = $property->getFieldName();
            $methodName = $property->getLocatorMethodName();
            $targetType = $property->getTargetType();
            $oneToOne = $property->getOneToOne();
            $proxyMethod = false;
            $proxySingleCachedLookup = false;
            $proxyOneToManyCachedLookup = false;
            $proxyManyToManyCachedLookup = false;
            if ($oneToOne) {
                if (class_exists($oneToOne)) {
                    $proxySingleCachedLookup = true;
                }
            } elseif ($targetType && class_exists($targetType) && !$property->isDateType()) {
                $targetReflection = new PantonoReflectionModel($targetType);
                if ($targetReflection->getDatabaseTable() || $targetReflection->getLocator()) {
                    $proxySingleCachedLookup = true;
                }

            }
            $oneToMany = $property->getOneToManyModel();
            if ($oneToMany) {
                $proxyOneToManyCachedLookup = true;
            }
            $manyToManyConfig = $this->getManyToManyConfig($property);
            if ($manyToManyConfig) {
                $proxyManyToManyCachedLookup = true;
            }
            if ($property->isLazy() === true && $methodName !== null && $fieldName) {
                $proxyMethod = true;
            }
            $getter = $property->getGetter();
            $setter = $property->getSetter();
            $methodBody = null;
            if ($proxyMethod) {
                $methodBody = $this->liveLookupMethod($property);
            }
            if ($proxySingleCachedLookup && !$methodBody) {
                $methodBody = $this->singleCachedLookupMethod($property);
            }
            if ($proxyOneToManyCachedLookup && !$methodBody) {
                $methodBody = $this->oneToManyCachedLookupMethod($property, $pantonoReflection);
            }
            if ($proxyManyToManyCachedLookup && !$methodBody) {
                $methodBody = $this->manyToManyCachedLookupMethod($property, $pantonoReflection, $manyToManyConfig);
            }
            if ($methodBody) {
                $getterMethod = $this->cloneMethod($reflection->getMethod($getter), $class, $namespace);
                $getterMethod->setBody($methodBody);
                if ($reflection->hasMethod($setter)) {
                    $sourceSetter = $reflection->getMethod($setter);
                    $method = $this->cloneMethod($sourceSetter, $class, $namespace);

                    $paramName = array_keys($method->getParameters())[0];
                    $setterBody = <<<SETTER_BODY
parent::$setter(\${$paramName});
\$this->completedLookups['$getter'] = true;
SETTER_BODY;
                    $method->setBody($setterBody);
                }
            }
        }
        $printer = new PsrPrinter();
        return '<?php' . PHP_EOL . $printer->printNamespace($namespace);
    }

    private function cloneMethod(\ReflectionMethod $reflectionMethod, ClassType $class, PhpNamespace $namespace): Method
    {
        $method = $class->addMethod($reflectionMethod->getName());
        if ($reflectionMethod->isPrivate()) {
            $method->setVisibility('private');
        } elseif ($reflectionMethod->isProtected()) {
            $method->setVisibility('protected');
        } elseif ($reflectionMethod->isPublic()) {
            $method->setVisibility('public');
        }
        if ($reflectionMethod->getReturnType() instanceof ReflectionNamedType) {
            $returnType = $reflectionMethod->getReturnType()->getName();
            if (str_contains($returnType, '\\')) {
                if (substr($returnType, 0, 1) === '?') {
                    $returnType = substr($returnType, 1);
                }
                $namespace->addUse($returnType);
            }
            $method->setReturnType($reflectionMethod->getReturnType()->getName())->setReturnNullable($reflectionMethod->getReturnType()->allowsNull());
        }
        foreach ($reflectionMethod->getParameters() as $parameter) {
            $method->addParameter(
                $parameter->getName()
            )->setType($parameter->getType())->setNullable($parameter->allowsNull());
        }
        return $method;
    }

    private function liveLookupMethod(PantonoReflectionProperty $property): string
    {
        $getter = $property->getGetter();
        $setter = $property->getSetter();
        $fieldName = $property->getFieldName();
        if ($fieldName === '$this') {
            $lookupValue = '$this';
        } else {
            $lookupValue = "\$this->hydratorParams['$fieldName']";
        }

        if ($property->getLocatorClassName()) {
            $locatorMethod = 'getClassAutoWire';
            $lookupDependency = $property->getLocatorClassName();
        } elseif ($property->getLocatorService()) {
            $locatorMethod = 'loadDependency';
            $lookupDependency = $property->getLocatorService();
        } else {
            throw new \RuntimeException('Cannot generate proxy method for property ' . $property->getFieldName());
        }
        $lookupMethod = $property->getLocatorMethodName();
        return <<<METHOD_BODY
global \$app;
if (isset(\$this->completedLookups['$getter'])) {
    return parent::$getter();
}
\$this->completedLookups['$getter'] = true;
\$value = $lookupValue?\$this->getLocator()->$locatorMethod('$lookupDependency')->$lookupMethod($lookupValue):null;
if (\$value) {
    parent::{$setter}(\$value);
}
return parent::{$getter}();
METHOD_BODY;
    }

    private function singleCachedLookupMethod(PantonoReflectionProperty $property): string
    {
        $setter = $property->getSetter();
        $getter = $property->getGetter();
        $fieldName = $property->getFieldName();
        $lookupValue = "\$this->hydratorParams['$fieldName']";
        $model = $property->getType();
        return <<<EAGER
if (isset(\$this->completedLookups['$getter']) && \$this->completedLookups['$getter'] === true) {
    return parent::{$getter}();
}
\$this->completedLookups['$getter'] = true;
\$hydrator = \$this->getLocator()->loadDependency('@Hydrator');
\$key = \Pantono\Utilities\CacheHelper::cleanCacheKey('{$model}__' . $lookupValue);
\$cachedValue = EphemeralCacheHelper::get(\$key);
/**
* @var \Pantono\Hydrator\Hydrator \$hydrator
*/
if (!\$cachedValue) {
    \$value = \$hydrator->lookupRecord(\\$model::class, $lookupValue); 
} else {
    \$value = \$hydrator->hydrate(\\$model::class, \$cachedValue);
}
if (\$value) {
    parent::{$setter}(\$value);
}
return parent::{$getter}();
EAGER;
    }

    /**
     * @param PantonoReflectionProperty $property
     * @param PantonoReflectionModel<object> $parentReflection
     * @return string
     */
    private function oneToManyCachedLookupMethod(PantonoReflectionProperty $property, PantonoReflectionModel $parentReflection): string
    {
        $setter = $property->getSetter();
        $getter = $property->getGetter();
        $model = $property->getOneToManyModel();
        $mappedBy = $property->getOneToManyMappedBy();
        $idColumn = $parentReflection->getDatabaseIdColumn();
        $lookupValue = "\$this->hydratorParams['$idColumn']";

        return <<<EAGER
if (isset(\$this->completedLookups['$getter']) && \$this->completedLookups['$getter'] === true) {
    return parent::{$getter}();
}
\$this->completedLookups['$getter'] = true;
\$hydrator = \$this->getLocator()->loadDependency('@Hydrator');
\$key = \Pantono\Utilities\CacheHelper::cleanCacheKey('{$model}__' . '$mappedBy' . '__' . $lookupValue);
\$cachedValue = EphemeralCacheHelper::get(\$key);
/**
* @var \Pantono\Hydrator\Hydrator \$hydrator
*/
\$value = [];
if (\$cachedValue !== null) {
    \$value = \$hydrator->hydrateSet(\\$model::class, \$cachedValue);
} else {
    \$value = \$hydrator->lookupRecords(\\$model::class, '$mappedBy', $lookupValue);
}
parent::{$setter}(\$value);
return parent::{$getter}();
EAGER;
    }

    /**
     * @param PantonoReflectionProperty $property
     * @param PantonoReflectionModel<object> $parentReflection
     * @param array{targetModel: class-string, joinTable: string, joinColumn: string, inverseJoinColumn: string} $manyToManyConfig
     * @return string
     */
    private function manyToManyCachedLookupMethod(
        PantonoReflectionProperty $property,
        PantonoReflectionModel $parentReflection,
        array $manyToManyConfig
    ): string
    {
        $setter = $property->getSetter();
        $getter = $property->getGetter();
        $model = $manyToManyConfig['targetModel'];
        $joinTable = $manyToManyConfig['joinTable'];
        $joinColumn = $manyToManyConfig['joinColumn'];
        $inverseJoinColumn = $manyToManyConfig['inverseJoinColumn'];
        $idColumn = $parentReflection->getDatabaseIdColumn();
        $lookupValue = "\$this->hydratorParams['$idColumn']";

        return <<<EAGER
if (isset(\$this->completedLookups['$getter']) && \$this->completedLookups['$getter'] === true) {
    return parent::{$getter}();
}
\$this->completedLookups['$getter'] = true;
\$hydrator = \$this->getLocator()->loadDependency('@Hydrator');
\$key = \Pantono\Utilities\CacheHelper::cleanCacheKey('{$model}__' . '$joinTable' . '__' . '$joinColumn' . '__' . '$inverseJoinColumn' . '__' . $lookupValue);
\$cachedValue = EphemeralCacheHelper::get(\$key);
/**
* @var \Pantono\Hydrator\Hydrator \$hydrator
*/
\$value = [];
if (\$cachedValue !== null) {
    \$value = \$hydrator->hydrateSet(\\$model::class, \$cachedValue);
} else {
    \$value = \$hydrator->lookupManyToManyRecords(\\$model::class, '$joinTable', '$joinColumn', '$inverseJoinColumn', $lookupValue);
}
parent::{$setter}(\$value);
return parent::{$getter}();
EAGER;
    }

    /**
     * @param PantonoReflectionProperty $property
     * @return array{targetModel: class-string, joinTable: string, joinColumn: string, inverseJoinColumn: string}|null
     */
    private function getManyToManyConfig(PantonoReflectionProperty $property): ?array
    {
        foreach ($property->getReflectionProperty()->getAttributes(ManyToManyAttribute::class) as $attribute) {
            $args = $attribute->getArguments();
            $targetModel = $args['targetModel'] ?? $args[3] ?? null;
            $joinTable = $args['joinTable'] ?? $args[0] ?? null;
            $joinColumn = $args['joinColumn'] ?? $args[1] ?? null;
            $inverseJoinColumn = $args['inverseJoinColumn'] ?? $args[2] ?? null;
            if (
                is_string($targetModel) &&
                class_exists($targetModel) &&
                is_string($joinTable) &&
                is_string($joinColumn) &&
                is_string($inverseJoinColumn)
            ) {
                return [
                    'targetModel' => $targetModel,
                    'joinTable' => $joinTable,
                    'joinColumn' => $joinColumn,
                    'inverseJoinColumn' => $inverseJoinColumn,
                ];
            }
        }
        return null;
    }
}
