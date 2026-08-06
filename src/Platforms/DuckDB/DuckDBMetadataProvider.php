<?php

namespace DuckDb\Dbal\Platforms\DuckDB;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\UnsupportedName;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\MatchType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Metadata\DatabaseMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ForeignKeyConstraintColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\IndexColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\MetadataProvider;
use Doctrine\DBAL\Schema\Metadata\PrimaryKeyConstraintColumnRow;
use Doctrine\DBAL\Schema\Metadata\SchemaMetadataRow;
use Doctrine\DBAL\Schema\Metadata\SequenceMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableColumnMetadataRow;
use Doctrine\DBAL\Schema\Metadata\TableMetadataRow;
use Doctrine\DBAL\Schema\Metadata\ViewMetadataRow;
use DuckDb\Dbal\Platforms\DuckDBPlatform;

final readonly class DuckDBMetadataProvider implements MetadataProvider
{
    /** @internal This class can be instantiated only by a database platform. */
    public function __construct(
        private Connection $connection,
        private DuckDBPlatform $platform,
    ) {}

    /** {@inheritDoc} */
    public function getAllDatabaseNames(): iterable
    {
        $sql = '
            SELECT database_name
            FROM duckdb_databases()
            WHERE NOT internal
            ORDER BY database_name
        ';
        foreach ($this->connection->iterateColumn($sql) as $databaseName) {
            yield new DatabaseMetadataRow($databaseName);
        }
    }

    /** {@inheritDoc} */
    public function getAllSchemaNames(): iterable
    {
        $sql = '
            SELECT schema_name
            FROM duckdb_schemas()
            WHERE database_name = current_database() AND NOT internal
            ORDER BY schema_name
        ';
        foreach ($this->connection->iterateColumn($sql) as $schemaName) {
            yield new SchemaMetadataRow($schemaName);
        }
    }

    /** {@inheritDoc} */
    public function getAllTableNames(): iterable
    {
        $sql = '
            SELECT schema_name, table_name
            FROM duckdb_tables()
            WHERE database_name = current_database() AND NOT internal
            ORDER BY schema_name, table_name
        ';
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            yield new TableMetadataRow($row['schema_name'], $row['table_name'], []);
        }
    }

    /** {@inheritDoc} */
    public function getTableColumnsForAllTables(): iterable
    {
        return $this->getTableColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getTableColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        if ($schemaName === null) {
            throw UnsupportedName::fromNullSchemaName(__METHOD__);
        }

        return $this->getTableColumns($schemaName, $tableName);
    }

    /**
     * @return iterable<TableColumnMetadataRow>
     *
     * @throws Exception
     */
    private function getTableColumns(?string $schemaName, ?string $tableName): iterable
    {
        $whereClause = '';
        if ($schemaName !== null && $tableName !== null) {
            $whereClause = sprintf('AND schema_name = %s AND table_name = %s', $this->connection->quote($schemaName), $this->connection->quote($tableName));
        }
        $sql = sprintf(
            '
                SELECT schema_name, table_name, column_name, column_default, is_nullable, data_type, numeric_precision, numeric_scale, comment
                FROM duckdb_columns()
                WHERE database_name = current_database() AND NOT internal
                %s
                ORDER BY schema_name, table_name, column_index
            ',
            $whereClause,
        );
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            $typeName = $this->getBaseTypeName($row['data_type']);
            $editor = Column::editor()
                ->setQuotedName($row['column_name'])
                ->setTypeName($this->platform->getDoctrineTypeMapping($typeName));
            if ($typeName === 'decimal' || $typeName === 'numeric') {
                if ($row['numeric_precision'] !== null) {
                    $editor->setPrecision((int) $row['numeric_precision']);
                }
                if ($row['numeric_scale'] !== null) {
                    $editor->setScale((int) $row['numeric_scale']);
                }
            }
            $autoincrement = str_contains($row['column_default'] ?? '', 'nextval(');
            $editor
                ->setNotNull(! $row['is_nullable'])
                // The sequence default of an auto-increment column is an implementation
                // detail and not reported, so it does not produce a default change diff.
                ->setAutoincrement($autoincrement)
                ->setDefaultValue($autoincrement ? null : $this->parseDefaultExpression($row['column_default']));
            if ($row['comment'] !== null) {
                $editor->setComment($row['comment']);
            }

            yield new TableColumnMetadataRow($row['schema_name'], $row['table_name'], $editor->create());
        }
    }

    private function getBaseTypeName(string $completeType): string
    {
        $typeName = strtolower($completeType);
        $parenPosition = strpos($typeName, '(');
        if ($parenPosition !== false) {
            return substr($typeName, 0, $parenPosition);
        }

        return $typeName;
    }

    /**
     * Parses a default value expression as given by DuckDB.
     */
    private function parseDefaultExpression(?string $expression): mixed
    {
        if ($expression === null || $expression === 'NULL') {
            return null;
        }
        if ($expression === 'true') {
            return true;
        }
        if ($expression === 'false') {
            return false;
        }

        return trim($expression, "'");
    }

    /** {@inheritDoc} */
    public function getIndexColumnsForAllTables(): iterable
    {
        return $this->getIndexColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getIndexColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        if ($schemaName === null) {
            throw UnsupportedName::fromNullSchemaName(__METHOD__);
        }

        return $this->getIndexColumns($schemaName, $tableName);
    }

    /**
     * @return iterable<IndexColumnMetadataRow>
     *
     * @throws Exception
     */
    private function getIndexColumns(?string $schemaName, ?string $tableName): iterable
    {
        $whereClause = '';
        if ($schemaName !== null && $tableName !== null) {
            $whereClause = sprintf('AND schema_name = %s AND table_name = %s', $this->connection->quote($schemaName), $this->connection->quote($tableName));
        }
        $sql = sprintf(
            '
                SELECT schema_name, table_name, index_name, is_unique, expressions::VARCHAR[] AS expressions
                FROM duckdb_indexes()
                WHERE database_name = current_database() AND NOT is_primary
                %s
                ORDER BY schema_name, table_name, index_name
            ',
            $whereClause,
        );
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            $columnNames = array_map(fn($name) => trim($name, '"'), (array) $row['expressions']);
            foreach (array_filter($columnNames) as $columnName) {
                yield new IndexColumnMetadataRow(
                    $row['schema_name'],
                    $row['table_name'],
                    $row['index_name'],
                    $row['is_unique'] ? IndexType::UNIQUE : IndexType::REGULAR,
                    false,
                    null,
                    $columnName,
                    null
                );
            }
        }
    }

    /** {@inheritDoc} */
    public function getPrimaryKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getPrimaryKeyConstraintColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getPrimaryKeyConstraintColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        if ($schemaName === null) {
            throw UnsupportedName::fromNullSchemaName(__METHOD__);
        }

        return $this->getPrimaryKeyConstraintColumns($schemaName, $tableName);
    }

    /**
     * @return iterable<PrimaryKeyConstraintColumnRow>
     *
     * @throws Exception
     */
    private function getPrimaryKeyConstraintColumns(?string $schemaName, ?string $tableName): iterable
    {
        $whereClause = '';
        if ($schemaName !== null && $tableName !== null) {
            $whereClause = sprintf('AND schema_name = %s AND table_name = %s', $this->connection->quote($schemaName), $this->connection->quote($tableName));
        }
        $sql = sprintf(
            "
                SELECT schema_name, table_name, constraint_name, constraint_column_names
                FROM duckdb_constraints()
                WHERE database_name = current_database() AND constraint_type = 'PRIMARY KEY'
                %s
                ORDER BY schema_name, table_name, constraint_name
            ",
            $whereClause,
        );
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            $columnNames = array_map(fn($name) => trim($name, '"'), (array) $row['constraint_column_names']);
            foreach (array_filter($columnNames) as $columnName) {
                yield new PrimaryKeyConstraintColumnRow($row['schema_name'], $row['table_name'], $row['constraint_name'], true, $columnName);
            }
        }
    }

    /** {@inheritDoc} */
    public function getForeignKeyConstraintColumnsForAllTables(): iterable
    {
        return $this->getForeignKeyConstraintColumns(null, null);
    }

    /** {@inheritDoc} */
    public function getForeignKeyConstraintColumnsForTable(?string $schemaName, string $tableName): iterable
    {
        if ($schemaName === null) {
            throw UnsupportedName::fromNullSchemaName(__METHOD__);
        }

        return $this->getForeignKeyConstraintColumns($schemaName, $tableName);
    }

    /**
     * @return iterable<ForeignKeyConstraintColumnMetadataRow>
     *
     * @throws Exception
     */
    private function getForeignKeyConstraintColumns(?string $schemaName, ?string $tableName): iterable
    {
        $whereClause = '';
        if ($schemaName !== null && $tableName !== null) {
            $whereClause = sprintf('AND schema_name = %s AND table_name = %s', $this->connection->quote($schemaName), $this->connection->quote($tableName));
        }
        $sql = sprintf(
            "
                SELECT schema_name, table_name, constraint_name, constraint_column_names, referenced_table, referenced_column_names
                FROM duckdb_constraints()
                WHERE database_name = current_database() AND constraint_type = 'FOREIGN KEY'
                %s
                ORDER BY schema_name, table_name, constraint_name
            ",
            $whereClause,
        );
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            $localColumnNames   = (array) $row['constraint_column_names'];
            $foreignColumnNames = (array) $row['referenced_column_names'];

            foreach ($localColumnNames as $index => $localColumn) {
                $localColumn = trim((string) $localColumn, '"');
                $foreignColumn = trim((string) ($foreignColumnNames[$index] ?? $localColumn), '"');
                if ($localColumn === '' || $foreignColumn === '') {
                    continue;
                }
                yield new ForeignKeyConstraintColumnMetadataRow(
                    $row['schema_name'],
                    $row['table_name'],
                    null,
                    $row['constraint_name'],
                    $row['schema_name'],
                    $row['referenced_table'],
                    MatchType::SIMPLE,
                    ReferentialAction::NO_ACTION,
                    ReferentialAction::NO_ACTION,
                    false,
                    false,
                    $localColumn,
                    $foreignColumn
                );
            }
        }
    }

    /** {@inheritDoc} */
    public function getTableOptionsForAllTables(): iterable
    {
        return $this->getTableOptions(null, null);
    }

    /** {@inheritDoc} */
    public function getTableOptionsForTable(?string $schemaName, string $tableName): iterable
    {
        if ($schemaName === null) {
            throw UnsupportedName::fromNullSchemaName(__METHOD__);
        }

        return $this->getTableOptions($schemaName, $tableName);
    }

    /**
     * @return iterable<TableMetadataRow>
     *
     * @throws Exception
     */
    private function getTableOptions(?string $schemaName, ?string $tableName): iterable
    {
        $whereClause = '';
        if ($schemaName !== null && $tableName !== null) {
            $whereClause = sprintf('AND schema_name = %s AND table_name = %s', $this->connection->quote($schemaName), $this->connection->quote($tableName));
        }

        $sql = sprintf(
            '
                SELECT schema_name, table_name
                FROM duckdb_tables()
                WHERE database_name = current_database() AND NOT internal
                %s
                ORDER BY schema_name, table_name
            ',
            $whereClause,
        );
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            yield new TableMetadataRow($row['schema_name'], $row['table_name'], []);
        }
    }

    /** {@inheritDoc} */
    public function getAllViews(): iterable
    {
        $sql = '
            SELECT schema_name, view_name, sql
            FROM duckdb_views()
            WHERE database_name = current_database() AND NOT internal
            ORDER BY schema_name, view_name
        ';
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            yield new ViewMetadataRow($row['schema_name'], $row['view_name'], $row['sql']);
        }
    }

    /** {@inheritDoc} */
    public function getAllSequences(): iterable
    {
        $sql = '
            SELECT schema_name, sequence_name, increment_by, start_value
            FROM duckdb_sequences()
            WHERE database_name = current_database()
            ORDER BY schema_name, sequence_name
        ';
        foreach ($this->connection->iterateAssociative($sql) as $row) {
            yield new SequenceMetadataRow($row['schema_name'], $row['sequence_name'], (int) $row['increment_by'], (int) $row['start_value'], null);
        }
    }
}
