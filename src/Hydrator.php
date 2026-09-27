<?php

declare(strict_types=1);

namespace Pantono\Hydrator;

use Pantono\Utilities\DateTimeParser;
use Pantono\Utilities\ApplicationHelper;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Hydrator\HydratorInterface;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Pantono\Utilities\CacheHelper;
use Pantono\Hydrator\Event\PreHydrateEvent;
use Pantono\Hydrator\Event\PostHydrateEvent;
use Pantono\Hydrator\Event\PreHydrateSetEvent;
use Pantono\Hydrator\Event\PostHydrateSetEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pantono\Utilities\Model\PantonoReflectionModel;
use Pantono\Utilities\Model\PantonoReflectionProperty;
use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Contracts\Attributes\Database\ManyToMany as ManyToManyAttribute;

class Hydrator implements HydratorInterface
{
    private ContainerInterface $container;
    private EventDispatcherInterface $dispatcher;
    private ?ApplicationCacheInterface $cache;
    private RelationLoader $relationLoader;
    private bool $isHydratingSet = false;

    public function __construct(ContainerInterface $container, EventDispatcherInterface $dispatcher, ?ApplicationCacheInterface $cache = null)
    {
        $this->container = $container;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
        $this->relationLoader = new RelationLoader(fn(): EagerLoadRepository => $this->getRepository());
    }

    /**
     * @param string $key
     * @param class-string $className
     * @param callable $callback
     */
    public function hydrateCached(string $key, string $className, callable $callback): mixed
    {
        if ($this->cache === null) {
            return $this->hydrate($className, $callback());
        }
        $key = CacheHelper::cleanCacheKey($key);
        /**
         * @var array<int,mixed>|null $value
         */
        $value = $this->cache->getCallback($key, $callback, [CacheHelper::cleanCacheKey($className)]);
        return $this->hydrate($className, $value);
    }

    /**
     * @param string $key
     * @param class-string $className
     * @param callable $callback
     */
    public function hydrateSetCached(string $key, string $className, callable $callback): mixed
    {
        if ($this->cache === null) {
            return $this->hydrateSet($className, $callback());
        }
        $key = CacheHelper::cleanCacheKey($key);
        /**
         * @var array<int, array<string, mixed>> $value
         */
        $value = $this->cache->getCallback($key, $callback, [CacheHelper::cleanCacheKey($className)]);

        return $this->hydrateSet($className, $value);
    }

    public function clearCache(string $key): void
    {
        $this->clearRelationCache();
        if ($this->cache) {
            $this->cache->delete($key);
        }
    }

    /** Clear raw relation results after writes or between jobs in a long-running worker. */
    public function clearRelationCache(): void
    {
        $this->relationLoader->reset();
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int|string, mixed>|null $hydrateData
     * @param list<string> $relations Explicit nested relation paths using property names.
     * @return T|null
     * @throws \ReflectionException
     */
    public function hydrate(string $className, ?array $hydrateData = [], array $relations = []): ?object
    {
        try {
            return $this->hydrateObject($className, $hydrateData, $relations);
        } catch (\Throwable $exception) {
            $this->relationLoader->discardPending();
            throw $exception;
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int|string, mixed>|null $hydrateData
     * @param list<string> $relations
     * @return T|null
     */
    private function hydrateObject(string $className, ?array $hydrateData, array $relations): ?object
    {
        if ($hydrateData === null) {
            return null;
        }
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist for hydration');
        }
        $event = new PreHydrateEvent($className, $hydrateData);
        $this->dispatcher->dispatch($event);
        $pantonoReflection = new PantonoReflectionModel($className);
        $hydrateData = $event->getHydrateData();
        /** @var \ReflectionClass<T> $reflectionClass */
        $reflectionClass = new \ReflectionClass($className);
        /** @var T $class */
        $class = $reflectionClass->newInstance();
        if (empty($hydrateData)) {
            return null;
        }
        if ($pantonoReflection->isCreateProxy()) {
            /** @var \ReflectionClass<T> $reflectionClass */
            $reflectionClass = $this->createProxyClass($className);
            /** @var T $class */
            $class = $reflectionClass->newInstance();
            if (method_exists($class, 'setHydratorParams')) {
                $class->setHydratorParams($hydrateData);
            }
            ProxyHydratorRegistry::bind($class, $this);
        }
        foreach ($pantonoReflection->getProperties() as $property) {
            $field = $property->getFieldName();
            $type = $property->getType();
            $manyToManyConfig = $this->getManyToManyConfig($property);
            /**
             * @var int|string|null $data
             */
            $data = $hydrateData[$field] ?? null;
            if ($data !== null || $field === '$this' || $property->getOneToManyModel() || $manyToManyConfig !== null) {
                if ($property->isLazy() === true) {
                    continue;
                }
                $locator = $property->getLocator();
                if ($locator !== null) {
                    $dependency = null;
                    if ($locator['className']) {
                        $dependency = $this->container->getLocator()->getClassAutoWire($locator['className']);
                    } elseif ($locator['serviceName']) {
                        $dependency = $this->container->getLocator()->loadDependency($locator['serviceName']);
                    }
                    if ($dependency) {
                        $method = $locator['methodName'];
                        if ($property->getFieldName() === '$this') {
                            $data = $class;
                        }
                        $data = $dependency->$method($data);
                    }
                } else {
                    $filter = $property->getFilter();
                    if ($property->isTypeBuiltIn() && is_string($type)) {
                        $type = strtolower($type);
                        if (str_starts_with($type, '?')) {
                            $type = substr($type, 1);
                        }
                        if ($type === 'int') {
                            $data = intval($data);
                        }
                        if ($type === 'float') {
                            $data = floatval($data);
                        }
                        if ($type === 'bool') {
                            if ($data === 'yes') {
                                $data = true;
                            }
                            if ($data === 'no') {
                                $data = false;
                            }
                            $data = (bool)$data;
                        }
                        if ($type === 'string' && $filter === 'trim' && is_string($data)) {
                            $data = trim($data);
                        }
                    } elseif ($property->isDateType()) {
                        $format = $property->getDateFormat();
                        if ($type === 'DateTime' || $type === 'DateTimeInterface') {
                            if ($format) {
                                $data = \DateTime::createFromFormat($format, strval($data));
                            } else {
                                $data = DateTimeParser::parseDate(strval($data));
                            }
                        }
                        if ($type === 'DateTimeImmutable') {
                            /**
                             * @var string $data
                             */
                            if ($format !== null) {
                                $data = \DateTimeImmutable::createFromFormat($format, strval($data));
                            } else {
                                $data = DateTimeParser::parseDateImmutable(strval($data));
                            }
                        }
                    } elseif ($property->getType() === 'array' && $property->getFilter()) {
                        $filter = $property->getFilter();
                        if ($data !== null) {
                            if ($filter === 'json_decode') {
                                $data = json_decode((string)$data, true);
                            }
                            if ($filter === 'explode') {
                                if (!$data) {
                                    $data = [];
                                } elseif (is_array($data)) {
                                    $data = array_filter((array)$data, function ($value) {
                                        return $value !== '';
                                    });
                                } else {
                                    if (is_string($data)) {
                                        $data = array_filter(explode(',', $data), function ($value) {
                                            return $value !== '';
                                        });
                                    }
                                }
                            }
                            if ($filter === 'array_from_string') {
                                if (is_string($data)) {
                                    $data = $this->createArrayFromFieldString((string)$data);
                                }
                            }
                        }
                    } else {
                        $data = null;
                    }
                }
                $setter = $property->getSetter();
                $hasSetter = $reflectionClass->hasMethod($setter);
                $parentHasSetter = $reflectionClass->hasMethod($setter);
                if (($hasSetter || $parentHasSetter) && $data !== null) {
                    $class->$setter($data);
                }
            }
        }
        $this->relationLoader->discover($className, $hydrateData, $relations);
        $event = new PostHydrateEvent($className, $hydrateData, $class);
        $this->dispatcher->dispatch($event);
        /** @var T $result */
        $result = $event->getResult();
        return $result;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int,array<string,mixed>> $data
     * @param list<string> $relations Explicit paths augment EagerLoad and may select Lazy relations.
     * @return array<T>
     */
    public function hydrateSet(string $className, array $data, array $relations = []): array
    {
        $outermost = !$this->isHydratingSet;
        $this->isHydratingSet = true;
        try {
            $event = new PreHydrateSetEvent($className, $data);
            $this->dispatcher->dispatch($event);
            $data = $event->getHydrateData();
            $items = [];
            foreach ($data as $item) {
                $hydrated = $this->hydrate($className, $item, $relations);
                if ($hydrated !== null) {
                    $items[] = $hydrated;
                }
            }

            $event = new PostHydrateSetEvent($className, $data, $items);
            $this->dispatcher->dispatch($event);
            if ($outermost) {
                $this->doPendingCacheLookups();
            }
            /** @var array<T> $result */
            $result = $event->getResult();
            return $result;
        } catch (\Throwable $exception) {
            $this->relationLoader->discardPending();
            throw $exception;
        } finally {
            if ($outermost) {
                $this->isHydratingSet = false;
            }
        }
    }

    /**
     * @param class-string $className
     * @param mixed $field
     * @return mixed
     */
    public function lookupRecord(string $className, mixed $field): mixed
    {
        if (!$field) {
            return null;
        }
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist');
        }
        $reflection = new PantonoReflectionModel($className);
        if (!$reflection->getLocator() && (is_int($field) || is_string($field)) && $this->relationLoader->hasRow($className, $field)) {
            return $this->hydrate($className, $this->relationLoader->getRow($className, $field));
        }
        if ($this->cache) {
            $key = CacheHelper::cleanCacheKey($className . '__' . $field);
            $value = $this->cache->get($key);
            if ($value && is_array($value)) {
                return $this->hydrate($className, $value);
            }
        }
        if ($reflection->getLocator()) {
            $args = $reflection->getLocator();
            $service = $args['serviceName'] ?? null;
            $methodName = $args['methodName'] ?? null;
            $locatorClassName = $args['className'] ?? null;
            if ($locatorClassName) {
                $dep = $this->container->getLocator()->getClassAutoWire($locatorClassName);
            } else {
                if ($service === null) {
                    throw new \RuntimeException('No locator service configured for ' . $className);
                }
                $dep = $this->container->getLocator()->loadDependency($service);
            }
            if (!$dep) {
                throw new \RuntimeException('Unable to load dependency ' . ($service ?: $locatorClassName) . '::' . $methodName);
            }

            return $dep->$methodName($field);
        }

        if ($reflection->getDatabaseTable() && $reflection->getDatabaseIdColumn()) {
            if (!is_string($field) && !is_int($field)) {
                return null;
            }
            $row = $this->relationLoader->getRow($className, $field);
            if ($row) {
                return $this->hydrate($className, $row);
            }
        }
        return null;
    }

    /**
     * @template T of object
     * @param class-string<T> $model
     * @param string $column
     * @param int|string $fieldValue
     * @return array<T>
     */
    public function lookupRecords(string $model, string $column, int|string $fieldValue): array
    {
        return $this->hydrateSet($model, $this->relationLoader->getRows($model, 'many', [$column], $fieldValue));
    }

    /**
     * @template T of object
     * @param class-string<T> $model
     * @return array<T>
     */
    public function lookupAll(string $model): array
    {
        $reflection = new PantonoReflectionModel($model);
        $table = $reflection->getDatabaseTable();
        if (!$table) {
            throw new \RuntimeException('Database table not set for ' . $model);
        }
        return $this->hydrateSet($model, $this->getRepository()->getAll($table));
    }

    /**
     * @template T of object
     * @param class-string<T> $model
     * @param string $joinTable
     * @param string $joinColumn
     * @param string $inverseJoinColumn
     * @param int|string $fieldValue
     * @return array<T>
     */
    public function lookupManyToManyRecords(
        string     $model,
        string     $joinTable,
        string     $joinColumn,
        string     $inverseJoinColumn,
        int|string $fieldValue
    ): array
    {
        return $this->hydrateSet($model, $this->relationLoader->getRows(
            $model, 'join', [$joinTable, $joinColumn, $inverseJoinColumn], $fieldValue
        ));
    }

    /**
     * @param class-string $className
     * @return \ReflectionClass<object>
     * @throws \ReflectionException
     */
    private function createProxyClass(string $className): \ReflectionClass
    {
        $dir = ApplicationHelper::getApplicationRoot() . '/cache/proxies/';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $reflection = new \ReflectionClass($className);
        $filename = $reflection->getFileName();
        if (!$filename) {
            throw new \RuntimeException('Unable to get filename for ' . $className);
        }
        $cacheKey = md5(filemtime($filename) . $className . ApplicationHelper::getReleaseTimestamp() . ProxyGenerator::CACHE_VERSION);
        $target = $dir . $cacheKey . '.php';
        $proxyClassName = $reflection->getShortName() . 'ProxyClass';
        if (!file_exists($target)) {
            $proxyGenerator = new ProxyGenerator();
            $proxyClass = $proxyGenerator->generateProxyClass($className);

            $tempFile = tempnam(dirname($target), 'proxy');
            file_put_contents($tempFile, $proxyClass);
            rename($tempFile, $target);
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }
        }
        /**
         * @var class-string $className
         */
        $className = '\\Pantono\\Proxy\\' . $proxyClassName;
        require_once $target;

        return new \ReflectionClass($className);
    }


    /**
     * Create array from field string
     *
     * @param string $string Allowed values string
     *
     * @return array
     */
    private function createArrayFromFieldString(string $string): array
    {
        $fields = [];
        foreach (explode(',', $string) as $field) {
            if (str_contains($field, ':')) {
                [$key, $value] = explode(':', $field);
                $fields[$key] = $value;
            } else {
                $fields[] = $field;
            }
        }

        return array_filter($fields);
    }

    public function doPendingCacheLookups(): void
    {
        $this->relationLoader->flush();
    }

    private function getRepository(): EagerLoadRepository
    {
        $repo = $this->container->getLocator()->loadDependency(':' . EagerLoadRepository::class);
        if ($repo instanceof EagerLoadRepository) {
            return $repo;
        }
        throw new \RuntimeException('Failed to get EagerLoadRepository instance');
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
