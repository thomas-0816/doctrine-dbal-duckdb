<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;

/**
 * A {@see Comparator} that includes newTable in TableDiff
 */
final class DuckDBComparator extends Comparator
{
    /**
     * {@inheritDoc}
     *
     * Additionally drops the primary key from the new table when its only column
     * was dropped.
     */
    public function compareTables(Table $oldTable, Table $newTable): TableDiff
    {
        $primaryKey = $newTable->getPrimaryKey();
        if ($primaryKey !== null && count($primaryKey->getIndexedColumns()) === 1) {
            $primaryKeyColumnName = $primaryKey->getIndexedColumns()[0]->getColumnName()->getIdentifier()->getValue();
            if (! $newTable->hasColumn($primaryKeyColumnName)) {
                $newTable->dropPrimaryKey();
            }
        }

        $diff = parent::compareTables($oldTable, $newTable);

        return new DuckDBTableDiff(
            oldTable: $oldTable,
            newTable: $newTable,
            addedColumns: $diff->getAddedColumns(),
            changedColumns: $diff->getChangedColumns(),
            droppedColumns: $diff->getDroppedColumns(),
            addedIndexes: $diff->getAddedIndexes(),
            modifiedIndexes: $diff->getModifiedIndexes(),
            droppedIndexes: $diff->getDroppedIndexes(),
            renamedIndexes: $diff->getRenamedIndexes(),
            addedForeignKeys: $diff->getAddedForeignKeys(),
            modifiedForeignKeys: $diff->getModifiedForeignKeys(),
            droppedForeignKeys: $diff->getDroppedForeignKeys()
        );
    }
}
