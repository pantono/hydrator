<?php

namespace Pantono\Hydrator\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Pantono\Hydrator\Hydrator;
use Pantono\Hydrator\Tests\MockObjects\SimpleModel;
use Pantono\Hydrator\Tests\MockObjects\DateTimeModel;
use Pantono\Hydrator\Tests\MockObjects\FloatModel;
use Pantono\Hydrator\Tests\MockObjects\JsonModel;
use Pantono\Hydrator\Tests\MockObjects\TrimModel;
use Pantono\Hydrator\Tests\MockObjects\DifferentFieldModel;
use Pantono\Hydrator\Tests\MockObjects\BoolModel;
use Pantono\Contracts\Container\ContainerInterface;
use Pantono\Contracts\Application\Cache\ApplicationCacheInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class HydratorTest extends TestCase
{
    private MockObject|ContainerInterface $container;
    private MockObject|ApplicationCacheInterface $cache;
    private MockObject|EventDispatcher $eventDispatcher;

    public function setUp(): void
    {
        $this->container = $this->getMockBuilder(ContainerInterface::class)->getMock();
        $this->cache = $this->getMockBuilder(ApplicationCacheInterface::class)->getMock();
        $this->eventDispatcher = $this->getMockBuilder(EventDispatcher::class)->getMock();
    }

    public function testSimpleHydrate(): void
    {
        $expected = new SimpleModel();
        $expected->setId(1);
        $expected->setName('test');

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(SimpleModel::class, ['id' => '1', 'name' => 'test'])
        );
    }

    public function testDateTimeHydrator(): void
    {
        $expected = new DateTimeModel();
        $expected->setId(2);
        $expected->setDate(new \DateTime('2021-01-01 00:00:00'));

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(
                DateTimeModel::class,
                ['id' => '2', 'date' => '2021-01-01 00:00:00']
            )
        );
        $this->assertInstanceOf(\DateTime::class, $expected->getDate());
    }

    public function testFloatHydrate(): void
    {
        $expected = new FloatModel();
        $expected->setId(1);
        $expected->setFloat(0.05);

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(FloatModel::class, ['id' => '1', 'float' => '0.05'])
        );
    }

    public function testBoolHydrate(): void
    {
        $expected = new BoolModel();
        $expected->setBool(true);

        $this->assertEquals($expected, $this->getHydrator()->hydrate(BoolModel::class, ['bool' => '1']));
        $this->assertEquals($expected, $this->getHydrator()->hydrate(BoolModel::class, ['bool' => 'yes']));
        $this->assertNotEquals(
            $expected->isBool(),
            $this->getHydrator()->hydrate(BoolModel::class, ['bool' => '0'])->isBool()
        );
    }

    public function testJsonHydrate(): void
    {
        $expected = new JsonModel();
        $expected->setData(['test' => 'one', 'test2' => 2]);

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(JsonModel::class, ['data' => '{"test":"one","test2":2}'])
        );
    }

    public function testTrimHydrate(): void
    {
        $expected = new TrimModel();
        $expected->setData('test');

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(TrimModel::class, ['data' => '    test      '])
        );
    }

    public function testDifferentField(): void
    {
        $expected = new DifferentFieldModel();
        $expected->setData('test');

        $this->assertEquals(
            $expected,
            $this->getHydrator()->hydrate(DifferentFieldModel::class, ['other_data' => 'test'])
        );
    }

    public function testHydrateCached(): void
    {
        $testClass = new class {
            private string $value;

            public function setValue(string $value): void
            {
                $this->value = $value;
            }

            public function getValue(): string
            {
                return $this->value;
            }
        };

        $className = get_class($testClass);
        $cacheKey = 'test_key';
        $data = ['value' => 'cached_value'];

        $callback = fn() => $data;
        // First call - cache miss
        $this->cache->expects($this->once())
            ->method('getCallback')
            ->with(
                $this->equalTo('test_key'),
                $callback,
                [$testClass::class]
            )
            ->willReturn($data);


        $result = $this->getHydrator()->hydrateCached(
            $cacheKey,
            $className,
            $callback
        );
        $this->assertSame('cached_value', $result->getValue());
    }


    private function getHydrator(): Hydrator
    {
        return new Hydrator($this->container, $this->eventDispatcher, $this->cache);
    }
}
