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

        $column = new Column($name, $type, $options);

        $this->_addColumn($column);

        return $column;
    }

    /**
     * {@inheritDoc}
     *
     * Additionally drops the primary key when it is defined on the dropped column.
     */
    public function dropColumn(string $name): self
    {
        $primaryKey = $this->getPrimaryKeyConstraint();
        if ($primaryKey !== null && count($primaryKey->getColumnNames()) === 1) {
            $primaryKeyColumnName = $this->trimQuotes(strtolower($primaryKey->getColumnNames()[0]->getIdentifier()->getValue()));
            if ($primaryKeyColumnName === $this->trimQuotes(strtolower($name))) {
                $this->dropPrimaryKey();
            }
        }

        parent::dropColumn($name);

        return $this;
    }
}
