<?php

namespace Pantono\Hydrator\Repository;

use Pantono\Database\Repository\DefaultRepository;
use Pantono\Utilities\Model\PantonoReflectionModel;

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
        return $this->getDb()->fetchAll($this->getDb()->select()->from($tableName)->where($columnName . ' in (?)', $ids));
    }
}
