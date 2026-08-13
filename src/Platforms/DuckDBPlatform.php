<?php

namespace DuckDb\DBAL\Platforms;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidColumnType\ColumnValuesRequired;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DateIntervalUnit;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\Keywords\KeywordList;
use Doctrine\DBAL\Platforms\TrimMode;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\IndexNameInvalid;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnquotedIdentifierFolding;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\SQL\Builder\DefaultSelectSQLBuilder;
use Doctrine\DBAL\SQL\Builder\SelectSQLBuilder;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;
use Doctrine\Deprecations\Deprecation;
use DuckDb\DBAL\Platforms\DuckDB\DuckDBMetadataProvider;
use DuckDb\DBAL\Platforms\Keywords\DuckDBKeywords;
use DuckDb\DBAL\Schema\DuckDBSchemaManager;
use DuckDb\DBAL\Schema\DuckDBTableDiff;
use DuckDb\DBAL\Schema\DuckDBType;

/**
 * The DuckDBPlatform class describes the specifics and dialects of the DuckDB
 * database platform.
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
        return '(' . $date . ' ' . $operator . ' INTERVAL ' . $interval . ' ' . $unit->value . ')';
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
        return '';
    }

    /**
     * {@inheritDoc}
     */
    public function getColumnDeclarationSQL(string $name, array $column): string
    {
        $charset = ! empty($column['charset']) ? ' ' . $this->getColumnCharsetDeclarationSQL($column['charset']) : '';
        $default = $this->getDefaultValueDeclarationSQL($column);
        $notnull = ! empty($column['notnull']) ? ' NOT NULL' : '';
        $collation = ! empty($column['collation']) ? ' ' . $this->getColumnCollationDeclarationSQL($column['collation']) : '';
        $typeDecl    = $column['columnDefinition'] ?? $column['type']->getSQLDeclaration($column, $this);
        $declaration = $typeDecl . $charset . $default . $notnull . $collation;

        return $name . ' ' . $declaration;
    }

    /**
     * {@inheritDoc}
     */
    protected function _getCreateTableSQL(string $name, array $columns, array $options = []): array
    {
        $this->validateCreateTableOptions($options, __METHOD__);

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

        $sql = [];
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
     *
     * DuckDB only supports creating indexes with an explicit name, so an empty
     * index name is rejected when generating the index SQL instead of
     * producing invalid `CREATE INDEX  ON ...` statements.
     */
    public function getCreateIndexSQL(Index $index, string $table): string
    {
        if ($index->getName() === '') {
            throw IndexNameInvalid::new('');
        }

        return parent::getCreateIndexSQL($index, $table);
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

    /**
     * {@inheritDoc}
     */
    public function getEnumDeclarationSQL(array $column): string
    {
        if (! isset($column['values']) || ! is_array($column['values']) || $column['values'] === []) {
            throw ColumnValuesRequired::new($this, 'ENUM');
        }

        $quotedValues = array_map(fn(string $value): string => $this->quoteStringLiteral($value), array_values($column['values']));

        return 'ENUM(' . implode(', ', $quotedValues) . ')';
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

    /** @internal The method should be only used from within the {@see AbstractPlatform} class hierarchy. */
    public function supportsCommentOnStatement(): bool
    {
        return true;
    }

    protected function getCommentOnTableSQL(string $tableName, ?string $comment): string
    {
        $tableName = new Identifier($tableName);

        return sprintf(
            'COMMENT ON TABLE %s IS %s',
            $tableName->getQuotedName($this),
            $comment === null ? 'NULL' : $this->quoteStringLiteral($comment),
        );
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

    /**
     * {@inheritDoc}
     */
    public function getAlterSchemaSQL(SchemaDiff $diff): array
    {
        $sql = [];
        foreach ($diff->getCreatedSchemas() as $schema) {
            $sql[] = $this->getCreateSchemaSQL($schema);
        }
        foreach ($diff->getAlteredSequences() as $sequence) {
            $sql[] = $this->getAlterSequenceSQL($sequence);
        }

        $createdTables    = $diff->getCreatedTables();
        $droppedTables    = $diff->getDroppedTables();
        $createdSequences = $diff->getCreatedSequences();
        $droppedSequences = $diff->getDroppedSequences();
        $renamedTables    = [];

        foreach ($createdSequences as $sequence) {
            $sql[] = $this->getCreateSequenceSQL($sequence);
        }
        // A dropped table whose create SQL is case-insensitively identical to a created
        // one is reported as a rename instead of a create/drop pair. Renaming a table
        // renames its auto-increment sequence along with it. DuckDB cannot rename
        // sequences, so the sequence is recreated with the properties of the original
        // one (e.g. its start value) carried over, avoiding the reuse of existing IDs.
        foreach ($droppedTables as $droppedTableKey => $droppedTable) {
            foreach ($createdTables as $createdTableKey => $createdTable) {
                if ($this->hasIdenticalTableSQL($droppedTable, $createdTable)) {
                    $droppedSequenceName = $this->autoIncrementSequenceName($droppedTable);
                    $createdSequenceName = $this->autoIncrementSequenceName($createdTable);
                    $droppedSequence = array_filter($droppedSequences, fn(Sequence $droppedSequence)
                        => $droppedSequence->getObjectName()->toString() === $droppedSequenceName);
                    $createdSequence = array_filter($createdSequences, fn(Sequence $createdSequence)
                        => $createdSequence->getObjectName()->toString() === $createdSequenceName && $createdSequence->getInitialValue() === 1);
                    if ($createdSequence !== [] && $droppedSequence !== []) {
                        $sql[] = sprintf(
                            '-- SELECT setval(%s, currval(%s), true)',
                            $this->quoteStringLiteral($createdSequence[0]->getObjectName()->toString()),
                            $this->quoteStringLiteral($droppedSequence[0]->getObjectName()->toString())
                        );
                    }
                    $renamedTables[] = [$droppedTableKey, $createdTableKey];
                }
            }
        }
        foreach ($diff->getAlteredTables() as $tableDiff) {
            foreach ($tableDiff->getChangedColumns() as $columnDiff) {
                if ($columnDiff->hasNameChanged()) {
                    $oldTable        = $tableDiff->getOldTable();
                    $oldSequenceName = $this->autoIncrementSequenceNameForColumn($oldTable, $columnDiff->getOldColumn()->getName());
                    $newSequenceName = $this->autoIncrementSequenceNameForColumn($oldTable, $columnDiff->getNewColumn()->getName());
                    $droppedSequence = array_filter($droppedSequences, fn(Sequence $droppedSequence)
                        => $droppedSequence->getObjectName()->toString() === $oldSequenceName);
                    $createdSequence = array_filter($createdSequences, fn(Sequence $createdSequence)
                        => $createdSequence->getObjectName()->toString() === $newSequenceName && $createdSequence->getInitialValue() === 1);
                    if ($droppedSequence !== [] && $createdSequence !== []) {
                        $sql[] = sprintf(
                            '-- SELECT setval(%s, currval(%s), true)',
                            $this->quoteStringLiteral($createdSequence[0]->getObjectName()->toString()),
                            $this->quoteStringLiteral($droppedSequence[0]->getObjectName()->toString())
                        );
                    }
                }
            }
        }
        foreach ($droppedSequences as $sequence) {
            $sql[] = $this->getDropSequenceSQL($sequence->getQuotedName($this));
        }

        foreach ($renamedTables as [$droppedTableKey, $createdTableKey]) {
            $droppedTable = $droppedTables[$droppedTableKey];
            $createdTable = $createdTables[$createdTableKey];
            $sql[] = 'ALTER TABLE ' . $droppedTable->getObjectName()->toString()
                . ' RENAME TO ' . $createdTable->getObjectName()->toString();
            unset($droppedTables[$droppedTableKey], $createdTables[$createdTableKey]);
        }
        $sql = array_merge(
            $sql,
            $this->getCreateTablesSQL(array_values($createdTables)),
            $this->getDropTablesSQL(array_values($droppedTables)),
        );
        foreach ($diff->getAlteredTables() as $tableDiff) {
            $sql = array_merge($sql, $this->getAlterTableSQL($tableDiff));
        }

        return $sql;
    }

    /**
     * Returns the name of the sequence backing the auto-increment column of a
     * single-column primary key, following the {@code <table>_<column>_seq}
     * naming convention.
     */
    private function autoIncrementSequenceName(Table $table): ?string
    {
        $primaryKey = $table->getPrimaryKey();
        if ($primaryKey !== null) {
            $pkColumns = $primaryKey->getColumns();
            if (count($pkColumns) === 1) {
                $column = $table->getColumn($pkColumns[0]);

                return $this->autoIncrementSequenceNameForColumn($table, $column->getName());
            }
        }
        return null;
    }

    /**
     * Returns the name of the sequence following the {@code <table>_<column>_seq}
     * naming convention for the given column.
     */
    private function autoIncrementSequenceNameForColumn(Table $table, string $columnName): string
    {
        return sprintf(
            '%s_%s_seq',
            $table->getObjectName()->toString(),
            $columnName,
        );
    }

    private function hasIdenticalTableSQL(Table $droppedTable, Table $createdTable): bool
    {
        $normalizeDropped = implode("\n", array_map(
            static fn(string $statement): string => str_replace($droppedTable->getObjectName()->toString(), '', strtolower($statement)),
            $this->getCreateTableSQL($droppedTable),
        ));
        $normalizeCreated = implode("\n", array_map(
            static fn(string $statement): string => str_replace($createdTable->getObjectName()->toString(), '', strtolower($statement)),
            $this->getCreateTableSQL($createdTable),
        ));

        return $normalizeDropped === $normalizeCreated;
    }

    /**
     * {@inheritDoc}
     */
    public function getAlterTableSQL(TableDiff $diff): array
    {
        $sql         = [];
        $table = $diff->getOldTable();
        $tableNameSQL = $table->getQuotedName($this);

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

            $comment = $addedColumn->getComment();
            if ($comment !== '') {
                $sql[] = $this->getCommentOnColumnSQL($tableNameSQL, $addedColumn->getQuotedName($this), $comment);
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
            if ($columnDiff->hasCommentChanged()) {
                $sql[] = $this->getCommentOnColumnSQL($tableNameSQL, $newColumn->getQuotedName($this), $newColumn->getComment());
            }
        }

        if ($diff instanceof DuckDBTableDiff && $diff->getNewTable()->getComment() !== $diff->getOldTable()->getComment()) {
            $sql[] = $this->getCommentOnTableSQL($tableNameSQL, $diff->getNewTable()->getComment());
        }

        return array_merge(
            $this->getPreAlterTableIndexSQL($diff),
            $sql,
            $this->getPostAlterTableIndexSQL($diff),
        );
    }

    private function getTypeSQLDeclaration(Column $column): string
    {
        return $column->getType()->getSQLDeclaration($column->toArray(), $this);
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
     * lookup, and types without a dedicated mapping (arrays, nested types,
     * enums) are reported as a {@see DuckDBType} that emits the type name
     * verbatim.
     *
     * Struct, union and map declarations are resolved to a dedicated type
     * carrying their parsed fields.
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

        return new DuckDBType(strtolower($dbType));
    }

    protected function initializeDoctrineTypeMappings(): void
    {
        $this->doctrineTypeMapping = [
            'date'       => 'date',
            'datetime'   => 'datetime',
            'interval'   => 'dateinterval',
            'bool'       => 'boolean',
            'boolean'    => 'boolean',
            'int'        => 'integer',
            'integer'    => 'integer',
            'bigint'     => 'bigint',
            'double'     => 'float',
            'float'      => 'smallfloat',
            'real'       => 'smallfloat',
            'decimal'    => 'decimal',
            'numeric'    => 'decimal',
            'char'       => 'string',
            'text'       => 'string',
            'varchar'    => 'string',
            'json'       => 'json',
            'time'       => 'time',
            'time without time zone' => 'time',
            'timestamp'  => 'datetime',
            'timestamp with time zone' => 'datetimetz',
            'timestamp without time zone' => 'datetime',
            'timestamptz' => 'datetimetz',
            'uuid'       => 'guid',
            'enum'       => 'enum',
            'blob'       => 'blob',
            'bytea'      => 'blob',
            'varbinary'  => 'blob',
            'binary'     => 'blob',
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
