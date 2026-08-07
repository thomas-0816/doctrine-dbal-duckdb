<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnDiff;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;

/**
 * A {@see TableDiff} that additionally carries the comment of the new table.
 *
 * DuckDB always (re-)sets the table comment when a table is altered, so the
 * comment is attached without comparing it against the old one.
 */
final class DuckDBTableDiff extends TableDiff /** @phpstan-ignore-line */
{
    /**
     * @param array<ForeignKeyConstraint> $droppedForeignKeys
     * @param array<Column>               $addedColumns
     * @param array<string, ColumnDiff>   $changedColumns
     * @param array<Column>               $droppedColumns
     * @param array<Index>                $addedIndexes
     * @param array<Index>                $modifiedIndexes
     * @param array<Index>                $droppedIndexes
     * @param array<string, Index>        $renamedIndexes
     * @param array<ForeignKeyConstraint> $addedForeignKeys
     * @param array<ForeignKeyConstraint> $modifiedForeignKeys
     */
    public function __construct(
        private Table $oldTable,
        private Table $newTable,
        array $addedColumns = [],
        array $changedColumns = [],
        array $droppedColumns = [],
        array $addedIndexes = [],
        array $modifiedIndexes = [],
        array $droppedIndexes = [],
        array $renamedIndexes = [],
        array $addedForeignKeys = [],
        array $modifiedForeignKeys = [],
        array $droppedForeignKeys = [],
    ) {
        parent::__construct(
            $oldTable,
            $addedColumns,
            $changedColumns,
            $droppedColumns,
            $addedIndexes,
            $modifiedIndexes,
            $droppedIndexes,
            $renamedIndexes,
            $addedForeignKeys,
            $modifiedForeignKeys,
            $droppedForeignKeys,
        );
    }

    public function getNewTable(): Table
    {
        return $this->newTable;
    }

    public function isEmpty(): bool
    {
        return $this->oldTable->getComment() === $this->newTable->getComment() && parent::isEmpty();
    }
}
