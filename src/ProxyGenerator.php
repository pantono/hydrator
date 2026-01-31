<?php

namespace Pantono\Hydrator;

use Nette\PhpGenerator\PhpNamespace;
use Pantono\Contracts\Application\Proxy\ProxyInterface;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Method;
use Nette\PhpGenerator\PsrPrinter;
use ReflectionNamedType;
use Pantono\Hydrator\Traits\LocatorAwareTrait;
use Pantono\Hydrator\Model\PantonoReflectionModel;
use Pantono\Hydrator\Model\PantonoReflectionProperty;
use Pantono\Utilities\EphemeralCacheHelper;

class ProxyGenerator
{
    public function generateProxyClass(string $className): string
    {
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist');
        }

        $reflection = new \ReflectionClass($className);
        $namespace = new PhpNamespace('Pantono\Proxy');
        $class = $namespace->addClass($reflection->getShortName() . 'ProxyClass');
        $class->setExtends($className);
        $namespace->addUse(LocatorAwareTrait::class);
        $class->addTrait(LocatorAwareTrait::class);
        $namespace->addUse($className);
        $namespace->addUse(EphemeralCacheHelper::class);
        $namespace->addUse(ProxyInterface::class);
        $class->addImplement(ProxyInterface::class);

        $class->addProperty('hydratorParams')->setType('array')->setValue([])->setVisibility('private');
        $class->addProperty('completedLookups')->setType('array')->setValue([])->setVisibility('private');

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
            if ($oneToOne) {
                if (class_exists($oneToOne)) {
                    $proxySingleCachedLookup = true;
                }
            } elseif ($targetType && class_exists($targetType)) {
                $proxySingleCachedLookup = true;
            }
            if ($property->isLazy() === true && $methodName !== null && $fieldName) {
                $proxyMethod = true;
            }
            $getter = $property->getGetter();
            $setter = $property->getSetter();
            $methodBody = null;
            if ($proxyMethod) {
                $methodBody = $this->liveLookupMethod($property, $namespace);
            }
            if ($proxySingleCachedLookup && !$methodBody) {
                $methodBody = $this->singleCachedLookupMethod($property, $namespace);
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

    private function liveLookupMethod(PantonoReflectionProperty $property, PhpNamespace $namespace): string
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
\$parentValue = parent::$getter();
if (isset(\$this->completedLookups['$getter'])) {
    return \$parentValue;
}
\$this->completedLookups['$getter'] = true;
\$value = $lookupValue?\$this->getLocator()->$locatorMethod('$lookupDependency')->$lookupMethod($lookupValue):null;
if (\$value) {
    parent::{$setter}(\$value);
}
return parent::{$getter}();
METHOD_BODY;
    }

    private function singleCachedLookupMethod(PantonoReflectionProperty $property, PhpNamespace $namespace): string
    {
        $setter = $property->getSetter();
        $getter = $property->getGetter();
        $fieldName = $property->getFieldName();
        $lookupValue = "\$this->hydratorParams['$fieldName']";
        $model = $property->getType();
        return <<<EAGER
\$key = \Pantono\Utilities\CacheHelper::cleanCacheKey('{$model}__' . $lookupValue);
\$cachedValue = EphemeralCacheHelper::get(\$key);
/**
* @var \Pantono\Hydrator\Hydrator \$hydrator
*/
\$hydrator = \$this->getLocator()->loadDependency('Hydrator');
if (!\$cachedValue) {
    \$value = \$hydrator->lookupRecord(\\$model::class, $lookupValue); 
} else {
    \$value = \$hydrator->hydrate(\\$model::class, \$cachedValue);
}
\$this->completedLookups['$getter'] = true;
parent::{$setter}(\$value);
return \$value;
EAGER;
    }
}
