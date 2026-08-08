<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Schema;

/**
 * A {@see Schema} whose tables are {@see DuckDBTable}s that tolerate
 * unregistered DuckDB type names.
 */
final class DuckDBSchema extends Schema // @phpstan-ignore-line
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

        return $table;
    }
}
