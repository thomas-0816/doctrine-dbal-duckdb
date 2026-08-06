<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Schema\DuckDBType;

/**
 * A {@see Table} that tolerates unregistered DuckDB type names.
 *
 * {@see Table::addColumn()} resolves the type name through {@see Type::getType()}
 * and throws {@see UnknownColumnType} for DuckDB-specific type names that are not
 * registered with the type registry (e.g. "varchar[]"). This subclass falls back
 * to a {@see DuckDBType} that emits the given type name verbatim.
 */
final class DuckDBTable extends Table /** @phpstan-ignore-line */
{
    /**
     * {@inheritDoc}
     */
    public function addColumn(string $name, string|Type $type, array $options = []): Column
    {
        if (is_string($type)) {
            try {
                $type = Type::getType($type);
            } catch (UnknownColumnType) {
                $type = new DuckDBType($type);
            }
        }

        $column = new Column($name, $type, $options);

        $this->_addColumn($column);

        return $column;
    }
}
