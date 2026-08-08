<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Exception\UnknownColumnType;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Schema\Types\DuckDBMapType;
use DuckDb\DBAL\Schema\Types\DuckDBStructType;
use DuckDb\DBAL\Schema\Types\DuckDBType;
use DuckDb\DBAL\Schema\Types\DuckDBUnionType;

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

        if (isset($options['fields'])) {
            $fields = (string) $options['fields'];
            if ($type instanceof DuckDBStructType) {
                $type = new DuckDBStructType($fields);
            } elseif ($type instanceof DuckDBUnionType) {
                $type = new DuckDBUnionType($fields);
            } elseif ($type instanceof DuckDBMapType) {
                $type = new DuckDBMapType($fields);
            }
            unset($options['fields']);
        }

        $column = new Column($name, $type, $options);

        $this->_addColumn($column);

        return $column;
    }
}
