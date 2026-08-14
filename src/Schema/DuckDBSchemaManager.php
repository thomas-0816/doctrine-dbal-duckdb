<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Types\EnumType;
use DuckDb\DBAL\Platforms\DuckDBPlatform;

/**
 * @extends AbstractSchemaManager<DuckDBPlatform>
 */
class DuckDBSchemaManager extends AbstractSchemaManager
{
    public function listSchemaNames(): array
    {
        return $this->connection->fetchFirstColumn("
            SELECT DISTINCT schema_name
            FROM duckdb_schemas()
            WHERE database_name = current_database()
            ORDER BY schema_name != 'main', schema_name
        ");
    }

    public function createComparator(): Comparator
    {
        return new DuckDBComparator($this->platform);
    }

    public function createForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        // DuckDB only supports foreign keys defined inline in a CREATE TABLE statement.
        throw NotSupported::new(__METHOD__);
    }

    public function dropForeignKey(string $name, string $table): void
    {
        // DuckDB does not support dropping constraints with ALTER TABLE.
        throw NotSupported::new(__METHOD__);
    }

    public function dropTable(string $name): void
    {
        $table  = trim($name, '"');
        $schema = null;
        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table, 2);
        }

        parent::dropTable($name);
    }

    /**
     * @deprecated Use the schema name and the unqualified table name separately instead.
     *
     * {@inheritDoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        return ($table['schema_name'] === $this->determineCurrentSchemaName()) ? $table['table_name'] : $table['schema_name'] . '.' . $table['table_name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $type = $this->platform->getDoctrineType($tableColumn['type']);

        $unsigned = (bool) preg_match('/^u(?:tinyint|smallint|integer|bigint|hugeint)$/', strtolower($tableColumn['type']));
        $precision = isset($tableColumn['precision']) ? (int) $tableColumn['precision'] : null;
        $scale     = isset($tableColumn['scale']) ? (int) $tableColumn['scale'] : null;

        $options = [
            'unsigned'  => $unsigned,
            'notnull'   => (bool) $tableColumn['notnull'],
            'default'   => $this->parseDefaultExpression($tableColumn['dflt_value'] ?? null),
        ];
        if ($precision !== null) {
            $options['precision'] = $precision;
        }
        if ($scale !== null) {
            $options['scale'] = $scale;
        }
        if (isset($tableColumn['comment'])) {
            $options['comment'] = $tableColumn['comment'];
        }
        if ($type instanceof EnumType) {
            $options['values'] = $this->parseEnumValues($tableColumn['type']);
        }

        return new Column($tableColumn['name'], $type, $options);
    }

    /**
     * Parses the values of a DuckDB enum type such as "ENUM('ab','cde')".
     *
     * @return list<string>
     */
    private function parseEnumValues(string $expression): array
    {
        preg_match_all("/'([^']*(?:''[^']*)*)'/", $expression, $matches);

        return array_values(array_filter($matches[1]));
    }

    /**
     * Parses a default value expression as given by DuckDB.
     */
    private function parseDefaultExpression(?string $expression): mixed
    {
        if ($expression === null || $expression === 'NULL') {
            return null;
        }
        if ($expression === 'true' || $expression === "CAST('t' AS BOOLEAN)") {
            return true;
        }
        if ($expression === 'false' || $expression === "CAST('f' AS BOOLEAN)") {
            return false;
        }

        return trim($expression, "'");
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableViewDefinition(array $view): View
    {
        return new View($view['schema_name'] . '.' . $view['view_name'], $view['sql']);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        return new ForeignKeyConstraint(
            $tableForeignKey['local'],
            $tableForeignKey['foreignTable'],
            $tableForeignKey['foreign'],
            $tableForeignKey['constraint_name'],
        );
    }

    protected function _getPortableSequenceDefinition(array $sequence): Sequence
    {
        $name = ($sequence['schema_name'] === $this->determineCurrentSchemaName())
            ? $sequence['sequence_name'] : $sequence['schema_name'] . '.' . $sequence['sequence_name'];

        return new Sequence($name, (int) $sequence['increment_by'], (int) $sequence['start_value']);
    }

    protected function determineCurrentSchemaName(): ?string
    {
        return $this->connection->fetchOne('SELECT current_schema()');
    }

    protected function selectTableNames(string $databaseName): Result
    {
        $sql = '
            SELECT schema_name, table_name
            FROM duckdb_tables()
            WHERE database_name = current_database() AND NOT internal
            ORDER BY table_name
        ';

        return $this->connection->executeQuery($sql);
    }

    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = $tableName !== null ? [$tableName] : [];
        $sql = "
            SELECT schema_name,
                table_name,
                column_name AS name,
                column_index AS cid,
                data_type AS type,
                NOT is_nullable AS notnull,
                column_default AS dflt_value,
                numeric_precision AS precision,
                numeric_scale AS scale,
                comment
            FROM duckdb_columns()
            WHERE database_name = current_database() AND NOT internal
            " . ($tableName !== null ? 'AND table_name = ?' : '') . "
            ORDER BY table_name, column_index
        ";

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableIndexesList(array $rows, string $tableName): array
    {
        $indexes = [];
        foreach ($rows as $row) {
            $indexName = $keyName = $row['key_name'];
            if ($row['primary']) {
                $keyName = 'primary';
            }
            $keyName = strtolower($keyName);
            if (!isset($indexes[$keyName])) {
                $options = ['lengths' => []];
                $indexes[$keyName] = new Index(
                    $indexName,
                    $row['column_names'],
                    ! $row['non_unique'],
                    $row['primary'],
                    $row['flags'] ?? [],
                    $options
                );
            }
        }

        return $indexes;
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = $tableName !== null ? [$tableName, $tableName] : [];
        $sql = "
            SELECT schema_name, table_name, index_name AS key_name, false AS primary, NOT is_unique AS non_unique, expressions::VARCHAR[] AS column_names
            FROM duckdb_indexes()
            WHERE database_name = current_database() AND NOT is_primary
            " . ($tableName !== null ? 'AND table_name = ?' : '') . "
            UNION ALL
            SELECT schema_name, table_name, 'primary' AS key_name, true AS primary, false AS non_unique, constraint_column_names AS column_names
            FROM duckdb_constraints()
            WHERE database_name = current_database() AND constraint_type = 'PRIMARY KEY'
            " . ($tableName !== null ? 'AND table_name = ?' : '') . "
            ORDER BY table_name, key_name
        ";

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = $tableName !== null ? [$tableName] : [];
        $sql = "
            SELECT schema_name,
                table_name,
                constraint_name,
                constraint_column_names AS local,
                referenced_table AS foreignTable,
                referenced_column_names AS foreign
            FROM duckdb_constraints()
            WHERE database_name = current_database() AND constraint_type = 'FOREIGN KEY'
            " . ($tableName !== null ? 'AND table_name = ?' : '') . "
            ORDER BY table_name, constraint_name
        ";

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $params = $tableName !== null ? [$tableName] : [];
        $sql = "
            SELECT schema_name, table_name, comment
            FROM duckdb_tables()
            WHERE database_name = current_database() AND NOT internal
            " . ($tableName !== null ? 'AND table_name = ?' : '');

        $tableOptions = [];
        foreach ($this->connection->iterateAssociative($sql, $params) as $row) {
            $tableOptions[$this->_getPortableTableDefinition($row)] = ['comment' => $row['comment']];
        }

        return $tableOptions;
    }
}
