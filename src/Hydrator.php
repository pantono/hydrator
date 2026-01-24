<?php

declare(strict_types=1);

namespace Pantono\Hydrator;

use Pantono\Utilities\StringUtilities;
use Pantono\Utilities\DateTimeParser;
use Pantono\Utilities\ApplicationHelper;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Hydrator\HydratorInterface;
use Pantono\Contracts\Attributes\Locator;
use Pantono\Utilities\ReflectionUtilities;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Pantono\Utilities\CacheHelper;
use Pantono\Hydrator\Event\PreHydrateEvent;
use Pantono\Hydrator\Event\PostHydrateEvent;
use Pantono\Hydrator\Event\PreHydrateSetEvent;
use Pantono\Hydrator\Event\PostHydrateSetEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

class Hydrator implements HydratorInterface
{
    private ContainerInterface $container;
    private EventDispatcherInterface $dispatcher;
    private ?ApplicationCacheInterface $cache;

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
        $value = $this->cache->getCallback($key, $callback);
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
        $value = $this->cache->getCallback($key, $callback);

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
        $hydrateData = $event->getHydrateData();
        /** @var \ReflectionClass<T> $reflectionClass */
        $reflectionClass = new \ReflectionClass($className);
        /** @var T $class */
        $class = $reflectionClass->newInstance();
        if (empty($hydrateData)) {
            return null;
        }
        $properties = [];
        $isLazy = false;
        foreach ($reflectionClass->getProperties() as $property) {
            $config = ReflectionUtilities::parseAttributesIntoConfig($property);
            $properties[] = [
                'config' => $config,
                'reflection_property' => $property
            ];
            if ($config['lazy'] === true) {
                $isLazy = true;
            }
        }
        if ($isLazy === true) {
            /** @var \ReflectionClass<T> $reflectionClass */
            $reflectionClass = $this->createProxyClass($className);
            /** @var T $class */
            $class = $reflectionClass->newInstance();
            if (method_exists($class, 'setHydratorParams')) {
                $class->setHydratorParams($hydrateData);
            }
        }
        foreach ($properties as $propertyInfo) {
            $config = $propertyInfo['config'];
            $property = $propertyInfo['reflection_property'];
            $field = $config['field_name'] ?: StringUtilities::snakeCase($property->getName());
            $type = $config['type'] ?? '';
            /**
             * @var int|string|null $data
             */
            $data = $hydrateData[$field] ?? null;
            if ($data !== null || $field === '$this') {
                if ($config['lazy'] === true) {
                    continue;
                }
                if ($config['hydrator'] !== null) {
                    [$dependencyString, $method] = explode('::', $config['hydrator']);
                    if ($field === '$this') {
                        $data = $class;
                    }
                    $dependency = null;
                    if (class_exists($dependencyString)) {
                        $dependency = $this->container->getLocator()->getClassAutoWire($dependencyString);
                    } else {
                        $dependency = $this->container->getLocator()->loadDependency('@' . $dependencyString);
                    }
                    $data = $dependency->$method($data);
                } else {
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
                    if (str_starts_with($type, '\\')) {
                        $type = substr($type, 1);
                    }
                    if ($type === 'datetime' || $type === 'datetimeinterface') {
                        if ($config['format'] !== null) {
                            $data = \DateTime::createFromFormat($config['format'], strval($data));
                        } else {
                            $data = DateTimeParser::parseDate(strval($data));
                        }
                    }
                    if ($type === 'datetimeimmutable') {
                        /**
                         * @var string $data
                         */
                        if ($config['format'] !== null) {
                            $data = \DateTimeImmutable::createFromFormat($config['format'], strval($data));
                        } else {
                            $data = DateTimeParser::parseDateImmutable(strval($data));
                        }
                    }
                }
                $setter = lcfirst(StringUtilities::camelCase('set' . ucfirst($property->getName())));
                if ($config['filter']) {
                    if ($config['filter'] === 'trim') {
                        $data = trim($data);
                    }
                    if ($config['filter'] === 'json_decode') {
                        $data = json_decode($data, true);
                    }
                    if ($config['filter'] === 'explode') {
                        if (!$data) {
                            $data = [];
                        } elseif (is_array($data)) {
                            $data = array_filter((array)$data, function ($value) {
                                return $value !== '';
                            });
                        } else {
                            $data = strval($data);
                            $data = array_filter(explode(',', $data), function ($value) {
                                return $value !== '';
                            });
                        }
                    }
                    if ($config['filter'] === 'array_from_string') {
                        $data = $this->createArrayFromFieldString($data);
                    }
                }
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
        if (!class_exists($className)) {
            throw new \RuntimeException('Class ' . $className . ' does not exist');
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
}
