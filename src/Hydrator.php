<?php

declare(strict_types=1);

namespace Pantono\Hydrator;

use Pantono\Utilities\DateTimeParser;
use Pantono\Utilities\ApplicationHelper;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Hydrator\HydratorInterface;
use Pantono\Contracts\Attributes\Locator;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Pantono\Utilities\CacheHelper;
use Pantono\Hydrator\Event\PreHydrateEvent;
use Pantono\Hydrator\Event\PostHydrateEvent;
use Pantono\Hydrator\Event\PreHydrateSetEvent;
use Pantono\Hydrator\Event\PostHydrateSetEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Pantono\Utilities\Model\PantonoReflectionModel;
use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Utilities\EphemeralCacheHelper;

class Hydrator implements HydratorInterface
{
    private ContainerInterface $container;
    private EventDispatcherInterface $dispatcher;
    private ?ApplicationCacheInterface $cache;
    /**
     * @var array<class-string, array<int|string>>
     */
    private array $pendingModelLookups = [];
    /**
     * @var array<class-string, array<string, array<int|string>>>
     */
    private array $pendingOneToManyLookups = [];
    private bool $isHydratingSet = false;

    public function __construct(ContainerInterface $container, EventDispatcherInterface $dispatcher, ?ApplicationCacheInterface $cache = null)
    {
        $this->container = $container;
        $this->dispatcher = $dispatcher;
        $this->cache = $cache;
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
        if ($this->cache) {
            $this->cache->delete($key);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int, mixed>|null $hydrateData
     * @return T|null
     * @throws \ReflectionException
     */
    public function hydrate(string $className, ?array $hydrateData = []): ?object
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
        }
        $isEagerLoad = $pantonoReflection->isEagerLoad();
        foreach ($pantonoReflection->getProperties() as $property) {
            $field = $property->getFieldName();
            $type = $property->getType();
            /**
             * @var int|string|null $data
             */
            $data = $hydrateData[$field] ?? null;
            if ($data !== null || $field === '$this' || $property->getOneToManyModel()) {
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
                        if ($isEagerLoad && $data) {
                            $targetType = $property->getTargetType();
                            $oneToOne = $property->getOneToOne();
                            if ($oneToOne) {
                                if (class_exists($oneToOne)) {
                                    $this->addDatabaseLookup($oneToOne, $data);
                                }
                            } elseif ($targetType && class_exists($targetType)) {
                                $this->addDatabaseLookup($targetType, $data);
                            }
                        }
                        $oneToMany = $property->getOneToManyModel();
                        if ($oneToMany && $isEagerLoad) {
                            $mappedBy = $property->getOneToManyMappedBy();
                            $idColumn = $pantonoReflection->getDatabaseIdColumn();
                            if ($idColumn && isset($hydrateData[$idColumn]) && $mappedBy) {
                                $this->addOneToManyLookup($oneToMany, $mappedBy, $hydrateData[$idColumn]);
                            }
                        }
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
        $event = new PostHydrateEvent($className, $hydrateData, $class);
        $this->dispatcher->dispatch($event);
        /** @var T $result */
        $result = $event->getResult();
        return $result;
    }

    public function lookupRecord(string $className, mixed $field): mixed
    {
        if (!$field) {
            return null;
        }
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist');
        }
        if ($this->cache) {
            $key = CacheHelper::cleanCacheKey($className . '__' . $field);
            $value = $this->cache->get($key);
            if ($value && is_array($value)) {
                return $this->hydrate($className, $value);
            }
        }
        $class = new \ReflectionClass($className);
        $attributes = $class->getAttributes(Locator::class);
        if (empty($attributes)) {
            return null;
        }
        $args = $attributes[0]->getArguments();
        $service = $args['serviceName'] ?? null;
        $methodName = $args['methodName'] ?? null;
        $className = $args['className'] ?? null;
        if ($className) {
            $dep = $this->container->getLocator()->getClassAutoWire($className);
        } else {
            $dep = $this->container->getLocator()->loadDependency($service);
        }
        if (!$dep) {
            throw new \RuntimeException('Unable to load dependency ' . ($service ?: $className) . '::' . $methodName);
        }

        return $dep->$methodName($field);
    }


    /**
     * @template T of object
     * @param class-string<T> $className
     * @param array<int,array<string,mixed>> $data
     * @return array<T>
     */
    public function hydrateSet(string $className, array $data): array
    {
        $event = new PreHydrateSetEvent($className, $data);
        $this->dispatcher->dispatch($event);
        $data = $event->getHydrateData();
        $items = [];
        foreach ($data as $item) {
            $hydrated = $this->hydrate($className, $item);
            if ($hydrated !== null) {
                $items[] = $hydrated;
            }
        }

        $event = new PostHydrateSetEvent($className, $data, $items);
        $this->dispatcher->dispatch($event);
        /** @var array<T> $result */
        $result = $event->getResult();
        return $result;
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
        $cacheKey = md5(filemtime($filename) . $className . ApplicationHelper::getReleaseTimestamp());
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

    /**
     * @param class-string $className
     * @param string|int $id
     * @return void
     */
    private function addDatabaseLookup(string $className, string|int $id): void
    {
        if (!isset($this->pendingModelLookups[$className])) {
            $this->pendingModelLookups[$className] = [];
        }
        $this->pendingModelLookups[$className][] = $id;
    }

    private function addOneToManyLookup(string $className, string $mappedBy, string|int $id): void
    {
        /** @var class-string $className */
        if (!isset($this->pendingOneToManyLookups[$className])) {
            $this->pendingOneToManyLookups[$className] = [];
        }
        if (!isset($this->pendingOneToManyLookups[$className][$mappedBy])) {
            $this->pendingOneToManyLookups[$className][$mappedBy] = [];
        }
        $this->pendingOneToManyLookups[$className][$mappedBy][] = $id;
    }

    public function doPendingCacheLookups(): void
    {
        if (empty($this->pendingModelLookups) && empty($this->pendingOneToManyLookups)) {
            return;
        }
        $repo = $this->getRepository();
        foreach ($this->pendingModelLookups as $model => $ids) {
            $pantonoReflection = new PantonoReflectionModel($model);
            $idColumn = $pantonoReflection->getDatabaseIdColumn();
            if (!$idColumn) {
                continue;
            }
            $output = $repo->lookupRecords($model, $ids);
            /** @var array<string, mixed> $row */
            foreach ($output as $row) {
                if (isset($row[$idColumn])) {
                    $key = CacheHelper::cleanCacheKey($model . '__' . $row[$idColumn]);
                    EphemeralCacheHelper::setItem($key, $row);
                }
            }
            unset($this->pendingModelLookups[$model]);
        }
        foreach ($this->pendingOneToManyLookups as $model => $lookups) {
            foreach ($lookups as $mappedBy => $ids) {
                $pantonoReflection = new PantonoReflectionModel($model);
                $table = $pantonoReflection->getDatabaseTable();
                if (!$table) {
                    continue;
                }
                $output = $repo->getDataIn($table, $mappedBy, $ids);
                $results = [];
                foreach ($output as $row) {
                    $results[$row[$mappedBy]][] = $row;
                }
                foreach ($ids as $id) {
                    $key = CacheHelper::cleanCacheKey($model . '__' . $mappedBy . '__' . $id);
                    EphemeralCacheHelper::setItem($key, $results[$id] ?? []);
                }
            }
            unset($this->pendingOneToManyLookups[$model]);
        }
    }

    private function getRepository(): EagerLoadRepository
    {
        $repo = $this->container->getLocator()->loadDependency(':' . EagerLoadRepository::class);
        if ($repo instanceof EagerLoadRepository) {
            return $repo;
        }
        throw new \RuntimeException('Failed to get EagerLoadRepository instance');
    }
}
