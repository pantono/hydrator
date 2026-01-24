<?php

namespace Pantono\Hydrator\Rules\PhpStan;

use PHPStan\Rules\Rule;
use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use PHPStan\Analyser\Scope;
use Pantono\Contracts\Attributes\Locator;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Reflection\ReflectionProvider;

/**
 * @implements Rule<Property>
 */
class LocatorMethodExistsRule implements Rule
{
    private ReflectionProvider $reflectionProvider;

    public function __construct(ReflectionProvider $reflectionProvider)
    {
        $this->reflectionProvider = $reflectionProvider;
    }

    public function getNodeType(): string
    {
        return Property::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];
        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if ($attr->name->toString() === Locator::class || $scope->resolveName($attr->name) === Locator::class) {
                    $args = $attr->args;
                    $methodName = null;
                    $className = null;

                    foreach ($args as $arg) {
                        if ($arg->name !== null) {
                            if ($arg->name->name === 'methodName') {
                                if ($arg->value instanceof Node\Scalar\String_) {
                                    $methodName = $arg->value->value;
                                }
                            } elseif ($arg->name->name === 'className') {
                                if ($arg->value instanceof Node\Expr\ClassConstFetch) {
                                    $className = $scope->resolveName($arg->value->class);
                                } elseif ($arg->value instanceof Node\Scalar\String_) {
                                    $className = $arg->value->value;
                                }
                            }
                        }
                    }

                    if ($methodName && $className) {
                        if (!$this->reflectionProvider->hasClass($className)) {
                            $errors[] = RuleErrorBuilder::message(sprintf('Class %s specified in Locator attribute does not exist.', $className))->build();
                            continue;
                        }

                        $classReflection = $this->reflectionProvider->getClass($className);
                        if (!$classReflection->hasMethod($methodName)) {
                            $errors[] = RuleErrorBuilder::message(sprintf('Method %s::%s() specified in Locator attribute does not exist.', $className, $methodName))->build();
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
