<?php

namespace DuckDb\DbalDuckdb\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;
use Doctrine\DBAL\Types\Type;
use DuckDb\DbalDuckdb\Types\PassthroughType;

/**
 * A {@see Table} that tolerates unregistered DuckDB type names.
 *
 * {@see Table::addColumn()} resolves the type name through {@see Type::getType()}
 * and throws {@see UnknownColumnType} for DuckDB-specific type names that are not
 * registered with the type registry (e.g. "varchar[]"). This subclass falls back
 * to a {@see PassthroughType} that emits the given type name verbatim.
 */
final class DuckDBTable extends Table /** @phpstan-ignore-line */
{
    /**
     * {@inheritDoc}
     */
    public function addColumn(string $name, string $typeName, array $options = []): Column
    {
        try {
            $type = Type::getType($typeName);
        } catch (UnknownColumnType) {
            $type = new PassthroughType($typeName);
        }

        $column = new Column($name, $type, $options);

        $this->_addColumn($column);

        return $column;
    }
}
