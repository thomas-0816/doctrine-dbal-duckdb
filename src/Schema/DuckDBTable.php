<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Schema\Table;

/**
 * A {@see Table} that tolerates unregistered DuckDB type names.
 */
final class DuckDBTable extends Table /** @phpstan-ignore-line */
{
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
