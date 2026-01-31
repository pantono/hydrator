<?php

namespace Pantono\Hydrator\Tests;

use PHPUnit\Framework\TestCase;
use Pantono\Hydrator\Hydrator;
use PHPUnit\Framework\MockObject\MockObject;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Pantono\Hydrator\Events\EagerLoadModelEvents;
use Pantono\Hydrator\Event\PreHydrateSetEvent;
use Pantono\Hydrator\Tests\MockObjects\EagerLoadModel;
use Pantono\Hydrator\Tests\MockObjects\DatabaseLookupModel;
use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Contracts\Locator\LocatorInterface;
use Pantono\Hydrator\Locator\StaticLocator;

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

    private function trainContainerToReturnRepository(Hydrator $hydrator): MockObject|EagerLoadRepository
    {
        $repo = $this->getMockBuilder(EagerLoadRepository::class)->disableOriginalConstructor()->getMock();
        $locator = $this->getMockBuilder(LocatorInterface::class)->getMock();
        StaticLocator::setLocator($locator);
        $locator->expects($this->once())->method('loadDependency')->with('Hydrator')->willReturn($hydrator);
        $locator->expects($this->once())->method('getClassAutoWire')->with(EagerLoadRepository::class)->willReturn($repo);
        $this->container->expects($this->once())->method('getLocator')->willReturn($locator);
        return $repo;
    }

    private function getHydrator(): Hydrator
    {
        return new Hydrator($this->container, $this->eventDispatcher, $this->cache);
    }
}
