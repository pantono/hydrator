<?php

declare(strict_types=1);

namespace Pantono\Hydrator;

use Pantono\Hydrator\Repository\EagerLoadRepository;
use Pantono\Utilities\Model\PantonoReflectionModel;
use Pantono\Utilities\Model\PantonoReflectionProperty;

/**
 * Raw database results scoped to one hydrator, never hydrated objects or caller projections.
 *
 * @phpstan-type Row array<string, mixed>
 * @phpstan-type Lookup array{model: class-string, kind: string, columns: list<string>, id: int|string, relations: list<string>}
 */
class RelationLoader
{
    private const BATCH_SIZE = 500;

    /** @var array<string, list<Row>> */
    private array $results = [];
    /** @var array<string, Lookup> */
    private array $pending = [];
    /** @var array<string, true> */
    private array $scanned = [];
    private bool $flushing = false;
    /** @param \Closure(): EagerLoadRepository $repository */
    public function __construct(private \Closure $repository)
    {
    }

    public function reset(): void
    {
        $this->results = [];
        $this->discardPending();
    }

    public function discardPending(): void
    {
        $this->pending = [];
        $this->scanned = [];
    }

    /** @param class-string $model */
    public function hasRow(string $model, int|string $id): bool
    {
        return array_key_exists($this->key($this->lookup($model, 'one', [], $id)), $this->results);
    }

    /** @param class-string $model
     * @return Row|null
     */
    public function getRow(string $model, int|string $id): ?array
    {
        $lookup = $this->lookup($model, 'one', [], $id);
        $key = $this->key($lookup);
        if (!array_key_exists($key, $this->results)) {
            $reflection = new PantonoReflectionModel($model);
            $table = $reflection->getDatabaseTable();
            $column = $reflection->getDatabaseIdColumn();
            if (!$table || !$column) {
                return null;
            }
            $row = ($this->repository)()->selectSingleRow($table, $column, $id);
            $this->results[$key] = $row ? [$row] : [];
        }
        return $this->results[$key][0] ?? null;
    }

    /** @param class-string $model
     * @param list<string> $columns
     * @return list<Row>
     */
    public function getRows(string $model, string $kind, array $columns, int|string $id): array
    {
        $lookup = $this->lookup($model, $kind, $columns, $id);
        $this->fetch([$lookup]);
        return $this->results[$this->key($lookup)] ?? [];
    }

    /**
     * Inspect metadata and raw rows only: no getters, locators or hydration events.
     * Explicit paths use PHP property names and may include Lazy relations.
     *
     * @param class-string $model
     * @param array<int|string, mixed> $row
     * @param list<string> $relations
     */
    public function discover(string $model, array $row, array $relations = []): void
    {
        $reflection = new PantonoReflectionModel($model);
        foreach ($reflection->getProperties() as $property) {
            $name = $property->getReflectionProperty()->getName();
            $selected = false;
            $children = [];
            foreach ($relations as $path) {
                [$head, $tail] = array_pad(explode('.', $path, 2), 2, null);
                if ($head === $name) {
                    $selected = true;
                    if ($tail !== null) {
                        $children[] = $tail;
                    }
                }
            }
            if (!$selected && (!$reflection->isEagerLoad() || $property->isLazy())) {
                continue;
            }
            if ($property->getLocator() || $property->isDateType()) {
                continue;
            }
            $config = $this->relation($property);
            if ($config === null) {
                continue;
            }
            [$target, $kind, $columns] = $config;
            $targetReflection = new PantonoReflectionModel($target);
            // A custom class locator may filter or construct objects; SQL is not equivalent.
            if ($kind === 'one' && $targetReflection->getLocator()) {
                continue;
            }
            if (!$targetReflection->getDatabaseTable() || ($kind !== 'many' && !$targetReflection->getDatabaseIdColumn())) {
                continue;
            }
            $field = $kind === 'one' ? $property->getFieldName() : $reflection->getDatabaseIdColumn();
            $id = $field === null ? null : ($row[$field] ?? null);
            if ((!is_int($id) && !is_string($id)) || ($kind === 'one' && !$id)) {
                continue;
            }
            $lookup = $this->lookup($target, $kind, $columns, $id, $children);
            $workKey = $this->key($lookup) . serialize($lookup['relations']);
            if (!isset($this->scanned[$workKey])) {
                $this->pending[$workKey] = $lookup;
            }
        }
    }

    public function flush(): void
    {
        if ($this->flushing) {
            return;
        }
        $this->flushing = true;
        try {
            while ($this->pending) {
                // Detach this level before discovering more work, including the same model.
                $level = $this->pending;
                $this->pending = [];
                $batches = [];
                foreach ($level as $lookup) {
                    $batchKey = serialize([$lookup['model'], $lookup['kind'], $lookup['columns']]);
                    $batches[$batchKey][] = $lookup;
                }
                foreach ($batches as $batch) {
                    $this->fetch($batch);
                }
                foreach ($level as $workKey => $lookup) {
                    $this->scanned[$workKey] = true;
                    foreach ($this->results[$this->key($lookup)] ?? [] as $row) {
                        $this->discover($lookup['model'], $row, $lookup['relations']);
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->discardPending();
            throw $exception;
        } finally {
            $this->flushing = false;
        }
    }

    /** @param list<Lookup> $lookups */
    private function fetch(array $lookups): void
    {
        $missing = [];
        foreach ($lookups as $lookup) {
            $key = $this->key($lookup);
            if (!array_key_exists($key, $this->results)) {
                $missing[$key] = $lookup;
            }
        }
        foreach (array_chunk($missing, self::BATCH_SIZE, true) as $chunk) {
            $first = reset($chunk);
            $model = $first['model'];
            $kind = $first['kind'];
            $columns = $first['columns'];
            $reflection = new PantonoReflectionModel($model);
            $table = $reflection->getDatabaseTable();
            $idColumn = $reflection->getDatabaseIdColumn();
            if (!$table || ($kind !== 'many' && !$idColumn)) {
                throw new \RuntimeException('Database table/id column not set for ' . $model);
            }
            $ids = array_values(array_map(static fn(array $item): int|string => $item['id'], $chunk));
            $repository = ($this->repository)();
            if ($kind === 'one') {
                $rows = $repository->lookupRecords($model, $ids);
                $groupColumn = $idColumn;
            } elseif ($kind === 'many') {
                $rows = $repository->getDataIn($table, $columns[0], $ids);
                $groupColumn = $columns[0];
            } else {
                assert($idColumn !== null);
                $rows = $repository->getManyToManyData($columns[0], $table, $columns[1], $columns[2], $idColumn, $ids);
                $groupColumn = '__pantono_join_id';
            }
            $grouped = [];
            foreach ($rows as $row) {
                $groupId = $row[$groupColumn] ?? null;
                if ($kind === 'join') {
                    unset($row['__pantono_join_id']);
                }
                if (is_int($groupId) || is_string($groupId)) {
                    $grouped[$groupId][] = $row;
                }
                $rowId = $idColumn === null ? null : ($row[$idColumn] ?? null);
                if (!$reflection->getLocator() && (is_int($rowId) || is_string($rowId))) {
                    $rowKey = $this->key($this->lookup($model, 'one', [], $rowId));
                    $this->results[$rowKey] = [$row];
                }
            }
            foreach ($chunk as $key => $lookup) {
                // [] is a resolved missing record/empty collection, distinct from no entry.
                $this->results[$key] = $grouped[$lookup['id']] ?? [];
            }
        }
    }

    /** @param Lookup $lookup */
    private function key(array $lookup): string
    {
        return serialize([$lookup['model'], $lookup['kind'], $lookup['columns'], (string)$lookup['id']]);
    }

    /** @param class-string $model
     * @param list<string> $columns
     * @param list<string> $relations
     * @return Lookup
     */
    private function lookup(string $model, string $kind, array $columns, int|string $id, array $relations = []): array
    {
        $relations = array_values(array_unique($relations));
        sort($relations);
        return ['model' => $model, 'kind' => $kind, 'columns' => $columns, 'id' => $id, 'relations' => $relations];
    }

    /** @return array{class-string, string, list<string>}|null */
    private function relation(PantonoReflectionProperty $property): ?array
    {
        $target = $property->getOneToManyModel();
        $mappedBy = $property->getOneToManyMappedBy();
        if ($target && class_exists($target) && $mappedBy) {
            return [$target, 'many', [$mappedBy]];
        }
        $target = $property->getManyToManyModel();
        $joinTable = $property->getManyToManyJoinTable();
        $joinColumn = $property->getManyToManyJoinColumn();
        $inverseColumn = $property->getManyToManyInverseJoinColumn();
        if ($target && class_exists($target) && $joinTable && $joinColumn && $inverseColumn) {
            return [$target, 'join', [$joinTable, $joinColumn, $inverseColumn]];
        }
        $target = $property->getOneToOne() ?? $property->getTargetType();
        return $target && class_exists($target) ? [$target, 'one', []] : null;
    }
}
