<?php

namespace Pantono\Hydrator\Tests;

use PHPUnit\Framework\TestCase;
use Pantono\Hydrator\Hydrator;
use PHPUnit\Framework\MockObject\MockObject;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Pantono\Hydrator\Tests\MockObjects\EagerLoadModel;
use Pantono\Hydrator\Tests\MockObjects\DatabaseLookupModel;
use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Contracts\Locator\LocatorInterface;
use Pantono\Hydrator\Locator\StaticLocator;
use Pantono\Hydrator\Tests\MockObjects\LazyLoadModelCollection;
use Pantono\Hydrator\Tests\MockServices\MockLookupService;
use Pantono\Hydrator\Tests\MockObjects\OneToManyObject;
use Pantono\Hydrator\Tests\MockObjects\DeepEagerLoadTopLevel;
use Pantono\Hydrator\Tests\MockObjects\DeepEagerLoadFirstLevel;
use Pantono\Hydrator\Tests\MockObjects\DeepEagerLoadSecondLevel;

class EagerLoadTest extends TestCase
{
    private MockObject|ContainerInterface $container;
    private MockObject|ApplicationCacheInterface $cache;
    private MockObject|EventDispatcher $eventDispatcher;

    public function setUp(): void
    {
        if (!defined('APPLICATION_PATH')) {
            define('APPLICATION_PATH', __DIR__ . '/../../');
        }
        $this->container = $this->getMockBuilder(ContainerInterface::class)->getMock();
        $this->cache = $this->getMockBuilder(ApplicationCacheInterface::class)->getMock();
        $this->eventDispatcher = $this->getMockBuilder(EventDispatcher::class)->getMock();
    }

    public function testEagerLoadCached()
    {
        $expected = new EagerLoadModel();
        $expected->setId(1);
        $lookup = new DatabaseLookupModel();
        $lookup->setId(1);
        $expected->setLookupModel($lookup);
        $hydrator = $this->getHydrator();
        $repo = $this->trainContainerToReturnRepository($hydrator);
        $repo->expects($this->once())->method('lookupRecords')->with(DatabaseLookupModel::class, [1])
            ->willReturn([['id' => 1]]);
        $output = $hydrator->hydrateSet(EagerLoadModel::class, [['id' => 1, 'lookup_model' => 1]]);
        $this->assertInstanceOf(EagerLoadModel::class, $output[0]);
        $this->assertEquals($lookup, $output[0]->getLookupModel());
    }

    public function testEagerLoadOneToMany()
    {
        $hydrator = $this->getHydrator();
        $locator = $this->getMockBuilder(LocatorInterface::class)->getMock();
        $repo = $this->getMockBuilder(EagerLoadRepository::class)->disableOriginalConstructor()->getMock();
        $repo->expects($this->any())->method('getDataIn')->willReturn([
            ['id' => 1, 'other_id' => 1],
            ['id' => 2, 'other_id' => 1],
            ['id' => 3, 'other_id' => 1],
            ['id' => 4, 'other_id' => 1],
            ['id' => 5, 'other_id' => 1],
            ['id' => 6, 'other_id' => 1],
            ['id' => 8, 'other_id' => 2],
            ['id' => 9, 'other_id' => 2],
        ]);
        $locator->expects($this->any())->method('loadDependency')->willReturnCallback(function ($item) use ($repo, $hydrator) {
            if ($item === ':' . EagerLoadRepository::class) {
                return $repo;
            }
            if ($item === Hydrator::class || $item === '@Hydrator') {
                return $hydrator;
            }
            return null;
        });
        $locator->expects($this->any())->method('getClassAutoWire')->willReturnCallback(function ($item) use ($hydrator) {
            if ($item === 'Pantono\Hydrator\Hydrator') {
                return $hydrator;
            }
            return null;
        });
        StaticLocator::setLocator($locator);
        $this->container->expects($this->once())->method('getLocator')->willReturn($locator);
        $output = $hydrator->hydrateSet(OneToManyObject::class, [['id' => 1]]);
        $this->assertCount(6, $output[0]->getData());
    }


    public function testEagerLoadOneToManySingleModel()
    {
        $hydrator = $this->getHydrator();
        $locator = $this->getMockBuilder(LocatorInterface::class)->getMock();
        $repo = $this->getMockBuilder(EagerLoadRepository::class)->disableOriginalConstructor()->getMock();
        $repo->expects($this->any())->method('getDataIn')->willReturn([
            ['id' => 1, 'other_id' => 1],
            ['id' => 2, 'other_id' => 1],
            ['id' => 3, 'other_id' => 1],
            ['id' => 4, 'other_id' => 1],
            ['id' => 5, 'other_id' => 1],
            ['id' => 6, 'other_id' => 1]
        ]);
        $locator->expects($this->any())->method('loadDependency')->willReturnCallback(function ($item) use ($repo, $hydrator) {
            if ($item === ':' . EagerLoadRepository::class) {
                return $repo;
            }
            if ($item === Hydrator::class || $item === '@Hydrator') {
                return $hydrator;
            }
            return null;
        });
        $locator->expects($this->any())->method('getClassAutoWire')->willReturnCallback(function ($item) use ($hydrator) {
            if ($item === 'Pantono\Hydrator\Hydrator') {
                return $hydrator;
            }
            return null;
        });
        StaticLocator::setLocator($locator);
        $this->container->expects($this->any())->method('getLocator')->willReturn($locator);
        $output = $hydrator->hydrate(OneToManyObject::class, ['id' => 1]);
        $this->assertCount(6, $output->getData());
    }

    public function testEagerLoadCollection()
    {
        $hydrator = $this->getHydrator();
        $locator = $this->getMockBuilder(LocatorInterface::class)->getMock();
        StaticLocator::setLocator($locator);
        $service = new MockLookupService();
        $locator->expects($this->once())->method('getClassAutoWire')->with(MockLookupService::class)->willReturn($service);
        $this->container->expects($this->once())->method('getLocator')->willReturn($locator);
        $output = $hydrator->hydrateSet(LazyLoadModelCollection::class, [['id' => 1]]);
        $expected = new LazyLoadModelCollection();
        $expected->setCategories([['id' => 1]]);
        $this->assertEquals($expected, $output[0]);
    }

    public function testEagerLoadNotCached()
    {
        $expected = new EagerLoadModel();
        $expected->setId(1);
        $lookup = new DatabaseLookupModel();
        $lookup->setId(1);
        $expected->setLookupModel($lookup);
        $hydrator = $this->getHydrator();
        $repo = $this->trainContainerToReturnRepository($hydrator);
        $repo->expects($this->once())->method('lookupRecords')->with(DatabaseLookupModel::class, [1])
            ->willReturn([]);
        $output = $hydrator->hydrateSet(EagerLoadModel::class, [['id' => 1, 'lookup_model' => 1]]);
        $this->assertInstanceOf(EagerLoadModel::class, $output[0]);
        $this->assertEquals($lookup, $output[0]->getLookupModel());
    }

    public function testEagerLoadMultipleLevels()
    {
        $hydrator = $this->getHydrator();
        $repo = $this->trainContainerToReturnRepository($hydrator);
        $repo->expects($this->any())->method('lookupRecords')
            ->willReturnCallback(function (string $input, array $ids) {
                if ($input === 'Pantono\Hydrator\Tests\MockObjects\DeepEagerLoadFirstLevel') {
                    return [['id' => 1, 'second' => 2]];
                }
                if ($input === 'Pantono\Hydrator\Tests\MockObjects\DeepEagerLoadSecondLevel') {
                    return [['id' => 2, 'output' => 'string']];
                }
            });
        $expected = new DeepEagerLoadTopLevel();
        $first = new DeepEagerLoadFirstLevel();
        $second = new DeepEagerLoadSecondLevel();
        $second->setOutput('string');
        $first->setSecond($second);
        $expected->setModel($first);
        $output = $this->getHydrator()->hydrateSet(DeepEagerLoadTopLevel::class, [['id' => 1, 'model' => 1]]);
        $this->assertEquals($second->getOutput(), $output[0]->getModel()->getSecond()->getOutput());
    }

    private function trainContainerToReturnRepository(Hydrator $hydrator): MockObject|EagerLoadRepository
    {
        $repo = $this->getMockBuilder(EagerLoadRepository::class)->disableOriginalConstructor()->getMock();
        $locator = $this->getMockBuilder(LocatorInterface::class)->getMock();
        StaticLocator::setLocator($locator);
        $locator->expects($this->any())->method('loadDependency')->willReturnCallback(function ($dep) use ($repo, $hydrator) {
            if ($dep === '@Hydrator') {
                return $hydrator;
            }
            return $repo;
        });
        $locator->expects($this->any())->method('getClassAutoWire')->willReturnCallback(function ($item) use ($hydrator) {
            return $hydrator;
        });
        $this->container->expects($this->any())->method('getLocator')->willReturn($locator);
        return $repo;
    }

    private function getHydrator(): Hydrator
    {
        return new Hydrator($this->container, $this->eventDispatcher, $this->cache);
    }
}
