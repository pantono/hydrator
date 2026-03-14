<?php

namespace Pantono\Hydrator\Repository;

use Pantono\Database\Repository\DefaultRepository;
use Pantono\Utilities\Model\PantonoReflectionModel;
use Doctrine\DBAL\ArrayParameterType;

class EagerLoadRepository extends DefaultRepository
{
    /**
     * @param class-string $model
     * @param array<int|string> $ids
     * @param string|null $table
     * @param string|null $idColumn
     * @return array<int,mixed>
     */
    public function lookupRecords(string $model, array $ids = [], ?string $table = null, ?string $idColumn = null): array
    {
        $reflection = new PantonoReflectionModel($model);
        if ($table === null) {
            $table = $reflection->getDatabaseTable();
        }
        if ($idColumn === null) {
            $idColumn = $reflection->getDatabaseIdColumn();
        }
        if (!$table || !$idColumn) {
            return [];
        }
        return $this->getDataIn($table, $idColumn, $ids);
    }

    /**
     * @param string $tableName
     * @param string $columnName
     * @param array<int|string> $ids
     * @return array
     */
    public function getDataIn(string $tableName, string $columnName, array $ids): array
    {
        $select = $this->getDb()->select('t.*')->from($tableName, 't')
            ->where('t.' . $columnName . ' in (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING);
        return $this->getDb()->fetchAll($select);
    }

    /**
     * @param string $joinTable
     * @param string $tableName
     * @param string $joinColumn
     * @param string $relatedColumn
     * @param string $relatedIdColumn
     * @param array<int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function getManyToManyData(
        string $joinTable,
        string $tableName,
        string $joinColumn,
        string $relatedColumn,
        string $relatedIdColumn = 'id',
        array  $ids = []
    ): array
    {
        if (empty($ids)) {
            return [];
        }
        return $this->getDb()->fetchAll(
            $this->getDb()->select('t.*, j.' . $joinColumn . ' as __pantono_join_id')->from($this->quoteTable($joinTable), 'j')
                ->innerJoin('j', $this->quoteTable($tableName), 't', 'j.' . $relatedColumn . ' = t.' . $relatedIdColumn)
                ->where('j.' . $joinColumn . ' in (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::STRING)
        );
    }
}
