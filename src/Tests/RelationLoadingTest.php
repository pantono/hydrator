<?php

namespace Pantono\Hydrator\Tests;

use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Locator\LocatorInterface;
use Pantono\Hydrator\Event\PostHydrateEvent;
use Pantono\Hydrator\Event\PreHydrateEvent;
use Pantono\Hydrator\Event\PreHydrateSetEvent;
use Pantono\Hydrator\Hydrator;
use Pantono\Hydrator\Locator\StaticLocator;
use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Hydrator\Tests\MockObjects\EagerLoadModel;
use Pantono\Hydrator\Tests\MockObjects\DatabaseLookupModel;
use Pantono\Hydrator\Tests\MockObjects\RelationParent;
use Pantono\Hydrator\Tests\MockObjects\LazyRelationParent;
use Pantono\Hydrator\Tests\MockObjects\RelationChild;
use Pantono\Hydrator\Tests\MockObjects\PlainRelationChild;
use Pantono\Hydrator\Tests\MockObjects\RelationNode;
use Pantono\Hydrator\Tests\MockObjects\LocatedRelation;
use Pantono\Hydrator\Tests\MockObjects\LocatorRelationParent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class RelationLoadingTest extends TestCase
{
    private EagerLoadRepository&MockObject $repository;
    private EventDispatcher $events;
    private LocatorInterface&MockObject $locator;
    private ContainerInterface&MockObject $container;
    private Hydrator $hydrator;
    private ?object $custom = null;

    protected function setUp(): void
    {
        if (!defined('APPLICATION_PATH')) {
            define('APPLICATION_PATH', __DIR__ . '/../..');
        }
        $this->repository = $this->createMock(EagerLoadRepository::class);
        $this->events = new EventDispatcher();
        $this->locator = $this->createMock(LocatorInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->container->method('getLocator')->willReturn($this->locator);
        $this->hydrator = new Hydrator($this->container, $this->events);
        $this->locator->method('loadDependency')->willReturnCallback(fn(string $name) => match ($name) {
            '@Hydrator' => $this->hydrator,
            ':' . EagerLoadRepository::class => $this->repository,
            'Custom' => $this->custom,
            default => null,
        });
        StaticLocator::setLocator($this->locator);
    }

    public function testNestedCollectionsBatchAcrossAllParentsAndReuseRows(): void
    {
        $this->repository->expects($this->once())->method('getDataIn')
            ->with('children', 'parent_id', [1, 2, 3])->willReturn([
                ['id' => 10, 'parent_id' => 1, 'node' => 100],
                ['id' => 11, 'parent_id' => 1, 'node' => 200],
                ['id' => 12, 'parent_id' => 2, 'node' => 100],
            ]);
        $this->repository->expects($this->once())->method('getManyToManyData')
            ->with('parent_child', 'children', 'parent_id', 'child_id', 'id', [1, 2, 3])->willReturn([
                ['id' => 13, 'parent_id' => 1, 'node' => 300, '__pantono_join_id' => 1],
                ['id' => 14, 'parent_id' => 2, 'node' => 400, '__pantono_join_id' => 2],
                ['id' => 13, 'parent_id' => 1, 'node' => 300, '__pantono_join_id' => 2],
            ]);
        $this->repository->expects($this->once())->method('lookupRecords')
            ->with(RelationNode::class, [100, 200, 300, 400])->willReturn([
                ['id' => 100, 'name' => 'A'], ['id' => 200, 'name' => 'B'],
                ['id' => 300, 'name' => 'C'], ['id' => 400, 'name' => 'D'],
            ]);
        $this->repository->expects($this->never())->method('selectSingleRow');
        $hydrated = [];
        $this->events->addListener(PostHydrateEvent::class, function ($event) use (&$hydrated) {
            $hydrated[] = $event->getClassName();
        });
        $parents = $this->hydrator->hydrateSet(RelationParent::class, [['id' => 1], ['id' => 2], ['id' => 3], ['id' => 1]]);
        $this->assertSame(array_fill(0, 4, RelationParent::class), $hydrated, 'Preloading must not hydrate children');
        $this->assertSame(['A', 'B'], array_map(fn($child) => $child->getNode()->getName(), $parents[0]->getChildren()));
        $this->assertSame('A', $parents[1]->getChildren()[0]->getNode()->getName());
        $this->assertSame(['D', 'C'], array_map(fn($child) => $child->getNode()->getName(), $parents[1]->getLinked()));
        $this->assertSame('C', $parents[0]->getLinked()[0]->getNode()->getName());
        $this->assertSame([], $parents[2]->getChildren());
        $this->assertSame([], $parents[2]->getLinked());
        $this->assertSame(10, $parents[3]->getChildren()[0]->getId());
        $again = $this->hydrator->hydrateSet(RelationParent::class, [['id' => 1], ['id' => 3]]);
        $this->assertSame('A', $again[0]->getChildren()[0]->getNode()->getName());
        $this->assertSame([], $again[1]->getLinked());
        $this->assertSame(13, $this->hydrator->lookupRecord(RelationChild::class, 13)->getId());
    }

    public function testSingleFallbackCachesMissingAndSuccessfulResultsAcrossInstances(): void
    {
        $this->repository->expects($this->exactly(2))->method('selectSingleRow')
            ->willReturnCallback(fn($table, $column, $id) => $id === 1 ? ['id' => 1] : null);
        $this->repository->expects($this->never())->method('lookupRecords');
        foreach ([1, 1, 2, 2] as $id) {
            $item = $this->hydrator->hydrate(EagerLoadModel::class, ['id' => 4, 'lookup_model' => $id]);
            $this->assertSame($id === 1 ? 1 : null, $item->getLookupModel()?->getId());
            $this->assertSame($item->getLookupModel(), $item->getLookupModel());
        }
        // Previously queued work is satisfied by the fallback cache too.
        $this->hydrator->doPendingCacheLookups();
    }

    public function testLazyCollectionsCacheEmptyResultsAndShareJoinRows(): void
    {
        $this->repository->expects($this->once())->method('getDataIn')->with('plain_children', 'parent_id', [1])->willReturn([]);
        $this->repository->expects($this->once())->method('getManyToManyData')->willReturn([
            ['id' => 10, 'parent_id' => 1, '__pantono_join_id' => 1],
        ]);
        $this->repository->expects($this->never())->method('selectSingleRow');
        foreach ([1, 1] as $id) {
            $parent = $this->hydrator->hydrate(LazyRelationParent::class, ['id' => $id]);
            $this->assertSame([], $parent->getOptionalChildren());
            $this->assertSame([], $parent->getOptionalChildren());
        }
        $first = $this->hydrator->lookupManyToManyRecords(PlainRelationChild::class, 'parent_child', 'parent_id', 'child_id', 1);
        $second = $this->hydrator->lookupManyToManyRecords(PlainRelationChild::class, 'parent_child', 'parent_id', 'child_id', 1);
        $this->assertSame(10, $first[0]->getId());
        $this->assertSame(10, $second[0]->getId());
        $this->assertNotSame($first[0], $second[0]);
        $this->assertSame(10, $this->hydrator->lookupRecord(PlainRelationChild::class, 10)->getId());
    }

    public function testExplicitPathsLoadLazyRelationsOnNonEagerChildren(): void
    {
        $this->repository->expects($this->exactly(2))->method('getDataIn')->willReturnCallback(function ($table, $column, $ids) {
            $this->assertSame([1, 2], $ids);
            return $table === 'children' ? [] : [
                ['id' => 10, 'parent_id' => 1, 'node' => 100],
                ['id' => 20, 'parent_id' => 2, 'node' => 200],
            ];
        });
        $this->repository->expects($this->once())->method('getManyToManyData')->willReturn([]);
        $this->repository->expects($this->once())->method('lookupRecords')->with(RelationNode::class, [100, 200])
            ->willReturn([['id' => 100], ['id' => 200]]);
        $this->repository->expects($this->never())->method('selectSingleRow');
        $parents = $this->hydrator->hydrateSet(RelationParent::class, [['id' => 1], ['id' => 2]], ['optionalChildren.node']);
        $this->assertSame(100, $parents[0]->getOptionalChildren()[0]->getNode()->getId());
        $this->assertSame(200, $parents[1]->getOptionalChildren()[0]->getNode()->getId());
    }

    public function testNonEagerAndLazyRelationsRemainDeferredByDefault(): void
    {
        $this->repository->expects($this->never())->method('lookupRecords');
        $this->repository->expects($this->never())->method('getDataIn');
        $this->repository->expects($this->once())->method('selectSingleRow')->with('nodes', 'id', 100)->willReturn(['id' => 100]);
        $items = $this->hydrator->hydrateSet(PlainRelationChild::class, [['id' => 1, 'node' => 100], ['id' => 2, 'node' => 100]]);
        $this->assertSame(100, $items[0]->getNode()->getId());
        $this->assertSame(100, $items[1]->getNode()->getId());
    }

    public function testExpandedPathsRescanAlreadyCachedCollections(): void
    {
        $this->repository->expects($this->once())->method('getDataIn')->with('plain_children', 'parent_id', [1, 2])->willReturn([
            ['id' => 10, 'parent_id' => 1, 'node' => 100], ['id' => 20, 'parent_id' => 2, 'node' => 200],
        ]);
        $this->repository->expects($this->once())->method('lookupRecords')->with(RelationNode::class, [100, 200])
            ->willReturn([['id' => 100], ['id' => 200]]);
        $this->repository->expects($this->never())->method('selectSingleRow');
        $this->hydrator->hydrateSet(LazyRelationParent::class, [['id' => 1], ['id' => 2]], ['children']);
        $parents = $this->hydrator->hydrateSet(LazyRelationParent::class, [['id' => 1], ['id' => 2]], ['children.node']);
        $this->assertSame(100, $parents[0]->getChildren()[0]->getNode()->getId());
        $this->assertSame(200, $parents[1]->getChildren()[0]->getNode()->getId());
    }

    public function testSelfReferencesAndCyclesDoNotLosePendingWork(): void
    {
        $calls = [];
        $this->repository->expects($this->exactly(3))->method('lookupRecords')->willReturnCallback(function ($model, $ids) use (&$calls) {
            $calls[] = $ids;
            return match ($ids) {
                [2] => [['id' => 2, 'next' => 3]],
                [3] => [['id' => 3, 'next' => 1]],
                [1] => [['id' => 1, 'next' => 2]],
            };
        });
        $this->repository->expects($this->never())->method('selectSingleRow');
        $nodes = $this->hydrator->hydrateSet(RelationNode::class, [['id' => 1, 'next' => 2], ['id' => 4, 'next' => 2]]);
        $this->assertSame([[2], [3], [1]], $calls);
        $this->assertSame(1, $nodes[0]->getNext()->getNext()->getNext()->getId());
        $this->assertSame(2, $nodes[1]->getNext()->getId());
        $this->hydrator->doPendingCacheLookups();
    }

    public function testEagerFailureAndHydrationFailureRestoreState(): void
    {
        $calls = 0;
        $this->repository->expects($this->exactly(2))->method('lookupRecords')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('database failure');
            }
            return [['id' => 1]];
        });
        $this->assertThrows(fn() => $this->hydrator->hydrateSet(EagerLoadModel::class, [['lookup_model' => 1]]));
        $listener = function () { throw new \RuntimeException('event failure'); };
        $this->events->addListener(PreHydrateSetEvent::class, $listener);
        $this->assertThrows(fn() => $this->hydrator->hydrateSet(EagerLoadModel::class, [['lookup_model' => 99]]));
        $this->events->removeListener(PreHydrateSetEvent::class, $listener);
        $items = $this->hydrator->hydrateSet(EagerLoadModel::class, [['lookup_model' => 1]]);
        $this->assertSame(1, $items[0]->getLookupModel()->getId());
    }

    public function testFailedGetterCanBeRetried(): void
    {
        $calls = 0;
        $this->repository->expects($this->exactly(2))->method('selectSingleRow')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('try again');
            }
            return ['id' => 10];
        });
        $item = $this->hydrator->hydrate(PlainRelationChild::class, ['id' => 1, 'node' => 10]);
        $this->assertThrows(fn() => $item->getNode());
        $this->assertSame(10, $item->getNode()->getId());
    }

    public function testCacheResetAndSeparateHydratorsReloadAfterMutation(): void
    {
        $this->repository->expects($this->exactly(4))->method('selectSingleRow')
            ->willReturnOnConsecutiveCalls(['id' => 1, 'name' => 'before'], ['id' => 1, 'name' => 'after'], null, ['id' => 1, 'name' => 'new job']);
        $this->assertSame('before', $this->hydrator->lookupRecord(RelationNode::class, 1)->getName());
        $this->assertSame('before', $this->hydrator->lookupRecord(RelationNode::class, 1)->getName());
        $this->hydrator->clearRelationCache();
        $this->assertSame('after', $this->hydrator->lookupRecord(RelationNode::class, 1)->getName());
        $this->hydrator->clearCache('application-key');
        $this->assertNull($this->hydrator->lookupRecord(RelationNode::class, 1));
        $this->assertNull($this->hydrator->lookupRecord(RelationNode::class, 1));
        $this->hydrator = new Hydrator($this->container, $this->events);
        $this->assertSame('new job', $this->hydrator->lookupRecord(RelationNode::class, 1)->getName());
    }

    public function testPartialAndEventModifiedRowsDoNotPoisonSharedCache(): void
    {
        $this->repository->expects($this->once())->method('selectSingleRow')->willReturn(['id' => 1, 'name' => 'database']);
        $counter = 0;
        $this->events->addListener(PreHydrateEvent::class, function ($event) use (&$counter) {
            $data = $event->getHydrateData();
            $data['name'] = ($data['name'] ?? 'partial') . ++$counter;
            $event->setHydrateData($data);
        });
        $this->assertSame('partial1', $this->hydrator->hydrate(RelationNode::class, ['id' => 1])->getName());
        $first = $this->hydrator->lookupRecord(RelationNode::class, 1);
        $second = $this->hydrator->lookupRecord(RelationNode::class, 1);
        $this->assertSame('database2', $first->getName());
        $this->assertSame('database3', $second->getName());
        $this->assertNotSame($first, $second);
    }

    public function testCustomLocatorsKeepTheirSemanticsAndLazyTiming(): void
    {
        $this->custom = new class {
            public array $calls = [];
            public function find($id): LocatedRelation
            {
                $this->calls[] = $id;
                $item = new LocatedRelation();
                $item->setId($id + 100);
                return $item;
            }
        };
        $this->repository->expects($this->never())->method('lookupRecords');
        $this->repository->expects($this->never())->method('selectSingleRow');
        $parents = $this->hydrator->hydrateSet(LocatorRelationParent::class, [
            ['located' => 1, 'immediate' => 2, 'deferred' => 3],
            ['located' => 1, 'immediate' => 2, 'deferred' => 3],
        ], ['located', 'deferred']);
        $this->assertSame([2, 2], $this->custom->calls);
        $this->assertSame(102, $parents[0]->getImmediate()->getId());
        $this->assertSame(101, $parents[0]->getLocated()->getId());
        $this->assertSame(101, $parents[1]->getLocated()->getId());
        $this->assertSame(103, $parents[0]->getDeferred()->getId());
        $this->assertSame([2, 2, 1, 1, 3], $this->custom->calls);
    }

    public function testBatchSizeBoundsQueriesAndDeduplicatesIds(): void
    {
        $calls = [];
        $this->repository->expects($this->exactly(2))->method('lookupRecords')->willReturnCallback(function ($model, $ids) use (&$calls) {
            $calls[] = $ids;
            return array_map(fn($id) => ['id' => $id], $ids);
        });
        $rows = array_map(fn($id) => ['lookup_model' => $id], range(1, 501));
        $rows[] = ['lookup_model' => '1'];
        $items = $this->hydrator->hydrateSet(EagerLoadModel::class, $rows);
        $this->assertSame([array_merge(['1'], range(2, 500)), [501]], $calls);
        $this->assertSame(501, $items[500]->getLookupModel()->getId());
    }

    public function testExplicitManyToManyPathsBatchAcrossNonEagerParents(): void
    {
        $this->repository->expects($this->never())->method('getDataIn');
        $this->repository->expects($this->once())->method('getManyToManyData')
            ->with('parent_child', 'plain_children', 'parent_id', 'child_id', 'id', [1, 2])->willReturn([
                ['id' => 10, 'node' => 100, '__pantono_join_id' => 1],
                ['id' => 20, 'node' => 200, '__pantono_join_id' => 2],
            ]);
        $this->repository->expects($this->once())->method('lookupRecords')->with(RelationNode::class, [100, 200])
            ->willReturn([['id' => 100], ['id' => 200]]);
        $this->repository->expects($this->never())->method('selectSingleRow');
        $parents = $this->hydrator->hydrateSet(LazyRelationParent::class, [['id' => 1], ['id' => 2]], ['linked.node']);
        $this->assertSame(100, $parents[0]->getLinked()[0]->getNode()->getId());
        $this->assertSame(200, $parents[1]->getLinked()[0]->getNode()->getId());
    }

    public function testEmptyJoinCacheIsInvalidatedAfterWrites(): void
    {
        $this->repository->expects($this->exactly(2))->method('getManyToManyData')
            ->willReturnOnConsecutiveCalls([], [['id' => 10, '__pantono_join_id' => 1]]);
        foreach ([1, 1] as $id) {
            $parent = $this->hydrator->hydrate(LazyRelationParent::class, ['id' => $id]);
            $this->assertSame([], $parent->getLinked());
            $this->assertSame([], $parent->getLinked());
        }
        $this->hydrator->clearRelationCache();
        $parent = $this->hydrator->hydrate(LazyRelationParent::class, ['id' => 1]);
        $this->assertSame(10, $parent->getLinked()[0]->getId());
    }

    public function testFailedCollectionGetterCanBeRetried(): void
    {
        $calls = 0;
        $this->repository->expects($this->exactly(2))->method('getDataIn')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                throw new \RuntimeException('try again');
            }
            return [['id' => 10, 'parent_id' => 1]];
        });
        $parent = $this->hydrator->hydrate(LazyRelationParent::class, ['id' => 1]);
        $this->assertThrows(fn() => $parent->getChildren());
        $this->assertSame(10, $parent->getChildren()[0]->getId());
    }

    public function testPostHydrationFailureDiscardsPendingWork(): void
    {
        $this->repository->expects($this->once())->method('lookupRecords')->with(DatabaseLookupModel::class, [2])
            ->willReturn([['id' => 2]]);
        $listener = function () { throw new \RuntimeException('event failed'); };
        $this->events->addListener(PostHydrateEvent::class, $listener);
        $this->assertThrows(fn() => $this->hydrator->hydrateSet(EagerLoadModel::class, [['lookup_model' => 1]]));
        $this->events->removeListener(PostHydrateEvent::class, $listener);
        $items = $this->hydrator->hydrateSet(EagerLoadModel::class, [['lookup_model' => 2]]);
        $this->assertSame(2, $items[0]->getLookupModel()->getId());
    }

    public function testProxiesUseTheirOriginalContextAndRemainSerializable(): void
    {
        $this->repository->expects($this->once())->method('selectSingleRow')->willReturn(['id' => 10]);
        $original = $this->hydrator;
        $item = $original->hydrate(PlainRelationChild::class, ['id' => 1, 'node' => 10]);
        $this->hydrator = $this->createMock(Hydrator::class);
        $this->hydrator->expects($this->never())->method('lookupRecord');
        $this->assertSame(10, $item->getNode()->getId());
        $this->hydrator = $original;
        $copy = unserialize(serialize($item));
        $this->assertSame(10, $copy->getNode()->getId());
        $unresolved = $original->hydrate(PlainRelationChild::class, ['id' => 2, 'node' => 10]);
        $this->assertSame(10, unserialize(serialize($unresolved))->getNode()->getId());
    }

    public function testAbsentAndZeroForeignKeysDoNotQuery(): void
    {
        $this->repository->expects($this->never())->method('lookupRecords');
        $this->repository->expects($this->never())->method('selectSingleRow');
        $items = $this->hydrator->hydrateSet(EagerLoadModel::class, [
            ['id' => 1], ['id' => 2, 'lookup_model' => null], ['id' => 3, 'lookup_model' => 0],
        ]);
        foreach ($items as $item) {
            $this->assertNull($item->getLookupModel());
        }
    }

    public function testLegacyProxyCacheFileIsIgnored(): void
    {
        $reflection = new \ReflectionClass(EagerLoadModel::class);
        $identity = filemtime($reflection->getFileName()) . EagerLoadModel::class . \Pantono\Utilities\ApplicationHelper::getReleaseTimestamp();
        $directory = \Pantono\Utilities\ApplicationHelper::getApplicationRoot() . '/cache/proxies/';
        $legacy = $directory . md5($identity) . '.php';
        file_put_contents($legacy, '<?php throw new \\RuntimeException("Legacy proxy loaded");');
        try {
            $this->assertInstanceOf(EagerLoadModel::class, $this->hydrator->hydrate(EagerLoadModel::class, ['id' => 1]));
            $this->assertFileExists($directory . md5($identity . \Pantono\Hydrator\ProxyGenerator::CACHE_VERSION) . '.php');
        } finally {
            unlink($legacy);
        }
    }

    private function assertThrows(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
