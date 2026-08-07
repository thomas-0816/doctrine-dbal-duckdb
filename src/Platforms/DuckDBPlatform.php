<?php

namespace DuckDb\DBAL\Platforms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\TrimMode;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\SQL\Builder\SelectSQLBuilder;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;
use DuckDb\DBAL\Platforms\DuckDB\DuckDBMetadataProvider;
use DuckDb\DBAL\Platforms\Keywords\DuckDBKeywords;
use DuckDb\DBAL\Schema\DuckDBSchemaManager;
use DuckDb\DBAL\Schema\DuckDBType;

/**
 * The DuckDBPlatform class describes the specifics and dialects of the DuckDB
 * database platform.
 *
 * @phpstan-import-type ColumnProperties from Column
 * @phpstan-import-type CreateTableParameters from AbstractPlatform
 */
class DuckDBPlatform extends AbstractPlatform
{
    public function __construct()
    {
        parent::__construct(UnquotedIdentifierFolding::NONE);
    }

    public function getCreateDatabaseSQL(string $name): string
    {
        throw NotSupported::new(__METHOD__);
    }

    public function getDropDatabaseSQL(string $name): string
    {
        throw NotSupported::new(__METHOD__);
    }

    public function getRegexpExpression(): string
    {
        return '~';
    }

    public function getModExpression(string $dividend, string $divisor): string
    {
        return $dividend . ' % ' . $divisor;
    }

    public function getTrimExpression(
        string $str,
        TrimMode $mode = TrimMode::UNSPECIFIED,
        ?string $char = null,
    ): string {
        $trimFn = match ($mode) {
            TrimMode::UNSPECIFIED,
            TrimMode::BOTH => 'TRIM',
            TrimMode::LEADING => 'LTRIM',
            TrimMode::TRAILING => 'RTRIM',
        };
        $arguments = [$str];

        if ($char !== null) {
            $arguments[] = $char;
        }

        return sprintf('%s(%s)', $trimFn, implode(', ', $arguments));
    }

    public function getSubstringExpression(string $string, string $start, ?string $length = null): string
    {
        if ($length === null) {
            return sprintf('SUBSTR(%s, %s)', $string, $start);
        }

        return sprintf('SUBSTR(%s, %s, %s)', $string, $start, $length);
    }

    public function getLocateExpression(string $string, string $substring, ?string $start = null): string
    {
        if ($start === null || $start === '1') {
            return sprintf('strpos(%s, %s)', $string, $substring);
        }

        return sprintf(
            'CASE WHEN strpos(SUBSTR(%1$s, %3$s), %2$s) > 0 THEN strpos(SUBSTR(%1$s, %3$s), %2$s) + %3$s - 1 ELSE 0 END',
            $string,
            $substring,
            $start,
        );
    }

    protected function getDateArithmeticIntervalExpression(
        string $date,
        string $operator,
        string $interval,
        DateIntervalUnit $unit,
    ): string {
        return '(' . $date . ' ' . $operator . ' ' . $interval . ' ' . $unit->value . ')';
    }

    public function getDateDiffExpression(string $date1, string $date2): string
    {
        return sprintf(
            "date_diff('day', %s, %s)",
            $date2,
            $date1,
        );
    }

    public function getDateTimeTzFormatString(): string
    {
        return 'Y-m-d H:i:sO';
    }

    /** @link https://duckdb.org/docs/sql/functions/system.html */
    public function getCurrentDatabaseExpression(): string
    {
        return 'current_database()';
    }

    public function createSelectSQLBuilder(): SelectSQLBuilder
    {
        return new DefaultSelectSQLBuilder($this, null, null);
    }

    public function getSetTransactionIsolationSQL(TransactionIsolationLevel $level): string
    {
        throw NotSupported::new(__METHOD__);
    }

    /**
     * {@inheritDoc}
     */
    public function getBooleanTypeDeclarationSQL(array $column): string
    {
        return 'BOOLEAN';
    }

    /**
     * {@inheritDoc}
     */
    public function getIntegerTypeDeclarationSQL(array $column): string
    {
        return (! empty($column['unsigned']) ? 'UINTEGER' : 'INTEGER')
            . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getBigIntTypeDeclarationSQL(array $column): string
    {
        return (! empty($column['unsigned']) ? 'UBIGINT' : 'BIGINT')
            . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getSmallIntTypeDeclarationSQL(array $column): string
    {
        return (! empty($column['unsigned']) ? 'USMALLINT' : 'SMALLINT')
            . $this->_getCommonIntegerTypeDeclarationSQL($column);
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP';
    }

    public function getDateTimeTzTypeDeclarationSQL(array $column): string
    {
        return 'TIMESTAMP WITH TIME ZONE';
    }

    /**
     * {@inheritDoc}
     */
    public function getDateTypeDeclarationSQL(array $column): string
    {
        return 'DATE';
    }

    /**
     * {@inheritDoc}
     */
    public function getTimeTypeDeclarationSQL(array $column): string
    {
        return 'TIME';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCommonIntegerTypeDeclarationSQL(array $column): string
    {
        // DuckDB has no AUTOINCREMENT keyword. Auto-increment columns are implemented
        // with sequences whose nextval default is injected in _getCreateTableSQL().
        return '';
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
    {
        $this->validateCreateTableOptions($options, __METHOD__);

        $sql = [];
        foreach ($columns as $index => $column) {
            if (empty($column['autoincrement'])) {
                continue;
            }

            $sequenceName = trim($name, '"') . '_' . trim($column['name'], '"') . '_seq';
            $columns[$index]['default'] = 'nextval(' . $this->quoteStringLiteral($sequenceName) . ')';
            $sql[] = 'CREATE SEQUENCE IF NOT EXISTS ' . $sequenceName;
        }
        $columnListSql = $this->getColumnDeclarationListSQL($columns);

        if (! empty($options['uniqueConstraints'])) {
            foreach ($options['uniqueConstraints'] as $definition) {
                $columnListSql .= ', ' . $this->getUniqueConstraintDeclarationSQL($definition);
            }
        }
        if (! empty($options['primary'])) {
            $columnListSql .= ', PRIMARY KEY (' . implode(', ', array_unique(array_values($options['primary']))) . ')';
        }
        if (isset($options['foreignKeys'])) {
            foreach ($options['foreignKeys'] as $foreignKey) {
                $columnListSql .= ', ' . $this->getForeignKeyDeclarationSQL($foreignKey);
            }
        }

        $sql[] = 'CREATE TABLE ' . $name . ' (' . $columnListSql . ')';

        if (! empty($options['indexes'])) {
            foreach ($options['indexes'] as $indexDef) {
                $sql[] = $this->getCreateIndexSQL($indexDef, $name);
            }
        }

        return $sql;
    }

    protected function getBinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BLOB';
    }

    protected function getVarcharTypeDeclarationSQLSnippet(?int $length): string
    {
        // DuckDB canonicalizes VARCHAR(n) to VARCHAR, so the length is omitted.
        return 'VARCHAR';
    }

    protected function getVarbinaryTypeDeclarationSQLSnippet(?int $length): string
    {
        return 'BLOB';
    }

    /**
     * {@inheritDoc}
     */
    public function getClobTypeDeclarationSQL(array $column): string
    {
        return 'VARCHAR';
    }

    /**
     * {@inheritDoc}
     */
    public function getJsonTypeDeclarationSQL(array $column): string
    {
        return 'JSON';
    }

    /**
     * {@inheritDoc}
     */
    public function getGuidTypeDeclarationSQL(array $column): string
    {
        return 'UUID';
    }

    /** @internal The method should be only used from within the {@see AbstractSchemaManager} class hierarchy. */
    public function getListViewsSQL(string $database): string
    {
        return "SELECT schema_name, view_name, sql FROM duckdb_views()"
            . " WHERE database_name = current_database() AND internal = false";
    }

    public function getCreateSequenceSQL(Sequence $sequence): string
    {
        return 'CREATE SEQUENCE ' . $sequence->getQuotedName($this)
            . ' START WITH ' . $sequence->getInitialValue()
            . ' INCREMENT BY ' . $sequence->getAllocationSize();
    }

    /** @internal The method should be only used from within the {@see AbstractSchemaManager} class hierarchy. */
    public function getListSequencesSQL(string $database): string
    {
        return 'SELECT schema_name,
                       sequence_name,
                       start_value,
                       increment_by
                FROM   duckdb_sequences()
                WHERE  database_name = current_database()';
    }

    public function getSequenceNextValSQL(string $sequence): string
    {
        return 'SELECT NEXTVAL(' . $this->quoteStringLiteral($sequence) . ')';
    }

    public function getEmptyIdentityInsertSQL(string $quotedTableName, string $quotedIdentifierColumnName): string
    {
        return 'INSERT INTO ' . $quotedTableName . ' (' . $quotedIdentifierColumnName . ') VALUES (DEFAULT)';
    }

    public function supportsIdentityColumns(): bool
    {
        return true;
    }

    public function supportsSequences(): bool
    {
        return true;
    }

    public function supportsSchemas(): bool
    {
        return true;
    }

    public function supportsSavepoints(): bool
    {
        return false;
    }

    /** @internal The method should be only used from within the {@see AbstractPlatform} class hierarchy. */
    public function supportsColumnCollation(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getCreateForeignKeySQL(ForeignKeyConstraint $foreignKey, string $table): string
    {
        // DuckDB only supports foreign keys defined inline in a CREATE TABLE statement.
        throw NotSupported::new(__METHOD__);
    }

    public function getDropForeignKeySQL(string $foreignKey, string $table): string
    {
        // DuckDB does not support dropping constraints with ALTER TABLE.
        throw NotSupported::new(__METHOD__);
    }

    public function getDropIndexSQL(string $name, string $table): string
    {
        if (str_contains($table, '.')) {
            [$schema] = explode('.', $table);
            $name     = $schema . '.' . $name;
        }

        return parent::getDropIndexSQL($name, $table);
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql = [];
        $table = $diff->getOldTable();
        $tableNameSQL = $table->getQuotedName($this);

        foreach ($diff->getDroppedIndexes() as $index) {
            if ($index->isPrimary()) {
                throw new NotSupported(sprintf(
                    'Dropping the primary key of table "%s" is not supported by DuckDB.',
                    $diff->getOldTable()->getName(),
                ));
            }
        }
        foreach ($diff->getAddedColumns() as $addedColumn) {
            // DuckDB does not support adding a NOT NULL column in a single statement,
            // so the NOT NULL constraint is applied with a separate statement.
            $column = $addedColumn->toArray();
            $notNull        = ! empty($column['notnull']);
            $column['notnull'] = false;

            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ADD COLUMN ' . $this->getColumnDeclarationSQL(
                $addedColumn->getQuotedName($this),
                $column,
            );
            if ($notNull) {
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ALTER COLUMN ' . $addedColumn->getQuotedName($this) . ' SET NOT NULL';
            }
        }
        foreach ($diff->getDroppedColumns() as $droppedColumn) {
            $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' DROP COLUMN ' . $droppedColumn->getQuotedName($this);
        }
        foreach ($diff->getChangedColumns() as $columnDiff) {
            $oldColumn = $columnDiff->getOldColumn();
            $newColumn = $columnDiff->getNewColumn();
            $oldColumnName = $oldColumn->getQuotedName($this);
            $newColumnName = $newColumn->getQuotedName($this);
            if ($columnDiff->hasNameChanged()) {
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' RENAME COLUMN ' . $oldColumnName . ' TO ' . $newColumnName;
            }
            if ($this->getTypeSQLDeclaration($oldColumn) !== $this->getTypeSQLDeclaration($newColumn)) {
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ALTER COLUMN ' . $newColumnName . ' SET DATA TYPE ' . $this->getTypeSQLDeclaration($newColumn);
            }
            if ($columnDiff->hasDefaultChanged()) {
                $defaultClause = ($newColumn->getDefault() === null)
                    ? ' DROP DEFAULT'
                    : ' SET' . $this->getDefaultValueDeclarationSQL($newColumn->toArray());
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ALTER COLUMN ' . $newColumnName . $defaultClause;
            }
            if ($columnDiff->hasNotNullChanged()) {
                $sql[] = 'ALTER TABLE ' . $tableNameSQL . ' ALTER COLUMN ' . $newColumnName . ' ' . ($newColumn->getNotnull() ? 'SET' : 'DROP') . ' NOT NULL';
            }
        }

        return array_merge(
            $this->getPreAlterTableIndexSQL($diff),
            $sql,
            $this->getPostAlterTableIndexSQL($diff),
        );
    }

    private function getTypeSQLDeclaration(Column $column): string
    {
        $type            = $column->getType();
        $columnDefinition = $column->toArray();
        $columnDefinition['autoincrement'] = false;

        return $type->getSQLDeclaration($columnDefinition, $this);
    }

    /** @return list<string> */
    private function getPreAlterTableIndexSQL(TableDiff $diff): array
    {
        $table = $diff->getOldTable();
        $tableNameSQL = $table->getQuotedName($this);

        // DuckDB refuses to alter any column that an index depends on, so all
        // non-primary indexes are dropped up front and recreated afterwards.
        $sql = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $sql[] = $this->getDropIndexSQL($index->getQuotedName($this), $tableNameSQL);
        }

        return $sql;
    }

    /** @return list<string> */
    private function getPostAlterTableIndexSQL(TableDiff $diff): array
    {
        $table = $diff->getOldTable();
        $tableNameSQL = $table->getQuotedName($this);

        $sql = [];
        foreach ($this->getIndexesInAlteredTable($diff) as $index) {
            if (! $index->isPrimary()) {
                $sql[] = $this->getCreateIndexSQL($index, $tableNameSQL);
            }
        }
        foreach ($diff->getAddedIndexes() as $index) {
            if ($index->isPrimary()) {
                $sql[] = $this->getCreatePrimaryKeySQL($index, $tableNameSQL);
            }
        }

        return $sql;
    }

    /** @return array<Index> */
    private function getIndexesInAlteredTable(TableDiff $diff): array
    {
        $oldTable = $diff->getOldTable();
        $indexes  = $oldTable->getIndexes();
        $nameMap  = $this->getDiffColumnNameMap($diff);

        foreach ($indexes as $key => $index) {
            foreach ($diff->getRenamedIndexes() as $oldIndexName => $renamedIndex) {
                if (strtolower($index->getName()) === strtolower($oldIndexName)) {
                    unset($indexes[$key]);
                }
            }
            foreach ($diff->getModifiedIndexes() as $modifiedIndex) {
                if (strtolower($index->getName()) === strtolower($modifiedIndex->getName())) {
                    unset($indexes[$key]);
                }
            }
            $changed      = false;
            $indexColumns = [];
            foreach ($index->getIndexedColumns() as $indexedColumn) {
                $columnName = str_replace('"', '', $indexedColumn->getColumnName()->toString());
                $normalizedColumnName  = strtolower($columnName);
                if (! isset($nameMap[$normalizedColumnName])) {
                    unset($indexes[$key]);
                    continue 2;
                }
                $indexColumns[] = $nameMap[$normalizedColumnName];
                if ($columnName !== $nameMap[$normalizedColumnName]) {
                    $changed = true;
                }
            }
            if (! $changed) {
                continue;
            }
            $indexes[$key] = new Index($index->getName(), $indexColumns, $index->isUnique(), $index->isPrimary(), $index->getFlags());
        }
        foreach ($diff->getDroppedIndexes() as $index) {
            unset($indexes[strtolower($index->getName())]);
        }
        foreach (array_merge($diff->getAddedIndexes(), $diff->getModifiedIndexes(), $diff->getRenamedIndexes()) as $index) {
            $indexName = $index->getName();
            if ($indexName !== '') {
                $indexes[strtolower($indexName)] = $index;
            } else {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    private function getDiffColumnNameMap(TableDiff $diff): array
    {
        $oldTable = $diff->getOldTable();

        $map = [];

        foreach ($oldTable->getColumns() as $column) {
            $columnName                   = $column->getName();
            $map[strtolower($columnName)] = $columnName;
        }

        foreach ($diff->getDroppedColumns() as $column) {
            unset($map[strtolower($column->getName())]);
        }

        foreach ($diff->getChangedColumns() as $columnDiff) {
            $columnName                   = $columnDiff->getOldColumn()->getName();
            $map[strtolower($columnName)] = $columnDiff->getNewColumn()->getName();
        }

        foreach ($diff->getAddedColumns() as $column) {
            $columnName                   = $column->getName();
            $map[strtolower($columnName)] = $columnName;
        }

        // @phpstan-ignore return.type
        return $map;
    }

    /**
     * Returns the Doctrine type for the given database type.
     *
     * DuckDB returns complete type declarations such as "DECIMAL(18,3)",
     * "VARCHAR[]" or "ENUM('a','b')". The parens are stripped before the
     * lookup, and types without a dedicated mapping (arrays, nested structs,
     * maps, unions, enums) are reported as a {@see DuckDBType} that emits the
     * type name verbatim.
     */
    public function getDoctrineType(string $dbType): Type
    {
        $typeName = strtolower($dbType);
        $parenPosition = strpos($typeName, '(');
        if ($parenPosition !== false) {
            $typeName = substr($typeName, 0, $parenPosition);
        }

        if ($this->hasDoctrineTypeMappingFor($typeName)) {
            return Type::getType($this->getDoctrineTypeMapping($typeName));
        }

        return new DuckDBType($typeName);
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'tinyint'    => 'smallint',
            'utinyint'   => 'smallint',
            'smallint'   => 'smallint',
            'usmallint'  => 'smallint',
            'int'        => 'integer',
            'integer'    => 'integer',
            'uinteger'   => 'integer',
            'bigint'     => 'bigint',
            'ubigint'    => 'string',
            'hugeint'    => 'string',
            'uhugeint'   => 'string',
            'bignum'     => 'string',
            'double'     => 'float',
            'float'      => 'smallfloat',
            'real'       => 'smallfloat',
            'decimal'    => 'decimal',
            'numeric'    => 'decimal',
            'blob'       => 'blob',
            'bytea'      => 'blob',
            'varbinary'  => 'blob',
            'bool'       => 'boolean',
            'boolean'    => 'boolean',
            'bpchar'     => 'string',
            'char'       => 'string',
            'text'       => 'string',
            'varchar'    => 'string',
            'bit'        => 'string',
            'bitstring'  => 'string',
            'geometry'   => 'json',
            'date'       => 'date',
            'datetime'   => 'datetime',
            'interval'   => 'dateinterval',
            'json'       => 'json',
            'struct'     => 'json',
            'time'       => 'time',
            'time with time zone' => 'time',
            'time without time zone' => 'time',
            'timestamp'  => 'datetime',
            'timestamp with time zone' => 'datetimetz',
            'timestamp without time zone' => 'datetime',
            'timestamptz' => 'datetimetz',
            'uuid'       => 'guid',
        ];
    }

    /** @deprecated */
    protected function createReservedKeywordsList(): KeywordList
    {
        Deprecation::triggerIfCalledFromOutside(
            'doctrine/dbal',
            'https://github.com/doctrine/dbal/pull/6607',
            '%s is deprecated.',
            __METHOD__,
        );

        return new DuckDBKeywords();
    }

    /**
     * {@inheritDoc}
     */
    public function getBlobTypeDeclarationSQL(array $column): string
    {
        return 'BLOB';
    }

    public function createMetadataProvider(Connection $connection): DuckDBMetadataProvider
    {
        return new DuckDBMetadataProvider($connection, $this);
    }

    public function createSchemaManager(Connection $connection): DuckDBSchemaManager
    {
        return new DuckDBSchemaManager($connection, $this);
    }
}
