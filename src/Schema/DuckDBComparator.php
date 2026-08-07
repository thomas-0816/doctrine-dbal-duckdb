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
     */
    public function compareTables(Table $oldTable, Table $newTable): TableDiff
    {
        $diff = parent::compareTables($oldTable, $newTable);

        return new DuckDBTableDiff(
            oldTable: $oldTable,
            newTable: $newTable,
            addedColumns: $diff->getAddedColumns(),
            changedColumns: $diff->getChangedColumns(),
            droppedColumns: $diff->getDroppedColumns(),
            addedIndexes: $diff->getAddedIndexes(),
            droppedIndexes: $diff->getDroppedIndexes(),
            renamedIndexes: $diff->getRenamedIndexes(),
            addedForeignKeys: $diff->getAddedForeignKeys(),
            modifiedForeignKeys: $diff->getModifiedForeignKeys(),
            droppedForeignKeys: $diff->getDroppedForeignKeys()
        );
    }
}
