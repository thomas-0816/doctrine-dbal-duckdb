<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;
use Doctrine\DBAL\Types\Type;

/**
 * A {@see Table} that tolerates unregistered DuckDB type names.
 */
final class DuckDBTable extends Table /** @phpstan-ignore-line */
{
    /**
     * {@inheritDoc}
     */
    public function addColumn(string $name, string $type, array $options = []): Column
    {
        try {
            $type = Type::getType($type);
        } catch (UnknownColumnType) {
            $type = new DuckDBType($type);
        }

        if ($type instanceof DuckDBStructType && isset($options['fields'])) {
            $type = new DuckDBStructType((string) $options['fields']);
            unset($options['fields']);
        }

        $column = new Column($name, $type, $options);

        $this->_addColumn($column);

        return $column;
    }
}
