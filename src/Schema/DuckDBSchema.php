<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * A {@see Schema} whose tables are {@see DuckDBTable}s that tolerate
 * unregistered DuckDB type names.
 */
final class DuckDBSchema extends Schema
{
    public function createTable(string $name): DuckDBTable
    {
        $table = new DuckDBTable(
            $name,
            [],
            [],
            [],
            [],
            [],
            $this->_schemaConfig->toTableConfiguration(),
        );

        $this->_addTable($table);

        foreach ($this->_schemaConfig->getDefaultTableOptions() as $option => $value) {
            $table->addOption($option, $value);
        }

        return $table;
    }
}
