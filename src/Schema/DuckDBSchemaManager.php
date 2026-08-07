<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use DuckDb\DBAL\Platforms\DuckDBPlatform;

/**
 * @extends AbstractSchemaManager<DuckDBPlatform>
 */
class DuckDBSchemaManager extends AbstractSchemaManager
{
    /**
     * {@inheritDoc}
     */
    public function listTables(): array
    {
        $configuration = $this->createSchemaConfig()->toTableConfiguration();

        return array_map(
            static fn(Table $table): DuckDBTable => new DuckDBTable(
                $table->getName(),
                $table->getColumns(),
                $table->getIndexes(),
                $table->getUniqueConstraints(),
                $table->getForeignKeys(),
                $table->getOptions(),
                $configuration,
            ),
            parent::listTables(),
        );
    }

    public function listSchemaNames(): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT schema_name
            FROM duckdb_schemas()
            WHERE database_name = current_database() AND NOT internal
            ORDER BY schema_name
            '
        );
    }

    public function createComparator(): Comparator
    {
        return new DuckDBComparator($this->platform);
    }

    public function createForeignKey(ForeignKeyConstraint $foreignKey, string $table): void
    {
        if ($table === '') {
            return;
        }

        $table = $this->introspectTableByUnquotedName($table);

        $this->alterTable(new TableDiff($table, addedForeignKeys: [$foreignKey]));
    }

    public function dropForeignKey(string $name, string $table): void
    {
        if ($table === '') {
            return;
        }

        $table = $this->introspectTableByUnquotedName($table);

        $foreignKey = $table->getForeignKey($name);

        $this->alterTable(new TableDiff($table, droppedForeignKeys: [$foreignKey]));
    }

    public function dropTable(string $name): void
    {
        // DuckDB does not allow dropping a sequence that a table still depends on,
        // so the table is dropped first and its autoincrement sequences afterwards.
        $table  = trim($name, '"');
        $schema = null;
        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table, 2);
        }
        $params = [$table];
        $sql = '
            SELECT column_default
            FROM duckdb_columns()
            WHERE database_name = current_database() AND NOT internal AND table_name = ?
        ';
        if ($schema !== null) {
            $sql .= ' AND schema_name = ?';
            $params[] = $schema;
        }
        $sequences = [];
        foreach ($this->connection->fetchFirstColumn($sql, $params) as $default) {
            if (preg_match("/nextval\('([^']+)'\)/", $default ?? '', $matches)) {
                $sequences[] = $matches[1];
            }
        }

        parent::dropTable($name);

        foreach ($sequences as $sequence) {
            $this->connection->executeStatement('DROP SEQUENCE IF EXISTS ' . $sequence);
        }
    }

    /**
     * @deprecated Use the schema name and the unqualified table name separately instead.
     *
     * {@inheritDoc}
     */
    protected function _getPortableTableDefinition(array $table): string
    {
        // @phpstan-ignore missingType.checkedException
        $currentSchema = $this->determineCurrentSchemaName();

        if ($table['schema_name'] === $currentSchema) {
            return $table['table_name'];
        }

        return $table['schema_name'] . '.' . $table['table_name'];
    }

    /**
     * {@inheritDoc}
     */
    protected function _getPortableTableColumnDefinition(array $tableColumn): Column
    {
        $dbType = strtolower($tableColumn['type']);

        $parenPosition = strpos($dbType, '(');
        if ($parenPosition !== false) {
            $dbType = trim(substr($dbType, 0, $parenPosition));
        }

        $type = $this->platform->getDoctrineType($dbType);

        $autoincrement = (bool) ($tableColumn['autoincrement'] ?? false);

        $precision = isset($tableColumn['precision']) ? (int) $tableColumn['precision'] : null;
        $scale     = isset($tableColumn['scale']) ? (int) $tableColumn['scale'] : null;

        $options = [
            // The sequence default of an auto-increment column is an implementation
            // detail and not reported, so it does not produce a default change diff.
            'autoincrement' => $autoincrement,
            'notnull'   => (bool) $tableColumn['notnull'],
            'default'   => $autoincrement ? null : ($tableColumn['dflt_value'] ?? null),
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
        if (isset($tableColumn['check']) && $type instanceof StringType) {
            $values = $this->parseEnumValues((string) $tableColumn['check']);
            if ($values !== null) {
                $type = Type::getType(Types::ENUM);
                $options['values'] = $values;
            }
        }

        return new Column($tableColumn['name'], $type, $options);
    }

    /**
     * Parses the allowed values of a column-level CHECK (col IN (...)) constraint.
     *
     * DuckDB normalizes such a constraint to "CHECK((col IN ('a', 'b', 'c')))".
     * Only constraints whose whole expression is an IN-list of string literals
     * over a single column are treated as enums.
     *
     * @return list<string>|null
     */
    private function parseEnumValues(string $checkText): ?array
    {
        if (! preg_match('/^\([^ ]+ IN \(([^\)]+)\)/', $checkText, $matches)) {
            return null;
        }

        return array_values(array_filter(preg_split("/'((?:[^']|'')*)',?/", $matches[1], -1, PREG_SPLIT_DELIM_CAPTURE) ?: []));
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
        return new Sequence($sequence['schema_name'] . '.' . $sequence['sequence_name'], (int) $sequence['increment_by'], (int) $sequence['start_value']);
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
        $params = [];
        $whereClause = '';
        if ($tableName !== null) {
            $params[] = $tableName;
            $whereClause = 'AND c.table_name = ?';
        }
        $sql = sprintf(
            "
            SELECT c.schema_name,
                c.table_name,
                c.column_name AS name,
                c.column_index AS cid,
                c.data_type AS type,
                NOT c.is_nullable AS notnull,
                c.column_default AS dflt_value,
                c.numeric_precision AS precision,
                c.numeric_scale AS scale,
                c.comment,
                chk.expression AS check
            FROM duckdb_columns() c
            LEFT JOIN (
                SELECT table_name, constraint_column_names[1] AS column_name, min(expression) AS expression
                FROM duckdb_constraints()
                WHERE database_name = current_database() AND NOT internal
                    AND constraint_type = 'CHECK'
                    AND array_length(constraint_column_names) = 1
                GROUP BY table_name, constraint_column_names[1]
            ) chk ON chk.table_name = c.table_name AND chk.column_name = c.column_name
            WHERE c.database_name = current_database() AND NOT c.internal
            %s
            ORDER BY c.table_name, c.column_index",
            $whereClause,
        );

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableColumns(string $databaseName, ?string $tableName = null): array
    {
        $result = [];
        foreach (parent::fetchTableColumns($databaseName, $tableName) as $row) {
            $row['autoincrement'] = str_contains($row['dflt_value'] ?? '', 'nextval(');
            $result[] = $row;
        }

        return $result;
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
            if (isset($indexes[$keyName])) {
                continue;
            }
            $options = ['lengths' => []];
            if (isset($row['where'])) {
                $options['where'] = $row['where'];
            }
            $indexes[$keyName] = new Index(
                $indexName,
                $row['column_names'],
                ! $row['non_unique'],
                $row['primary'],
                $row['flags'] ?? [],
                $options
            );
        }

        return $indexes;
    }

    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];
        $whereClause = '';
        if ($tableName !== null) {
            $params[] = $tableName;
            $params[] = $tableName;
            $whereClause = 'AND table_name = ?';
        }
        $sql = sprintf(
            "
            SELECT schema_name, table_name, index_name AS key_name, false AS primary, NOT is_unique AS non_unique, expressions::VARCHAR[] AS column_names
            FROM duckdb_indexes()
            WHERE database_name = current_database() AND NOT is_primary
            %s
            UNION ALL
            SELECT schema_name, table_name, 'primary' AS key_name, true AS primary, false AS non_unique, constraint_column_names AS column_names
            FROM duckdb_constraints()
            WHERE database_name = current_database() AND constraint_type = 'PRIMARY KEY'
            %s
            ORDER BY table_name, key_name",
            $whereClause,
            $whereClause
        );

        return $this->connection->executeQuery($sql, $params);
    }

    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $params = [];
        $whereClause = '';
        if ($tableName !== null) {
            $params[] = $tableName;
            $whereClause = 'AND table_name = ?';
        }
        $sql = sprintf(
            "
            SELECT schema_name,
                table_name,
                constraint_name,
                constraint_column_names AS local,
                referenced_table AS foreignTable,
                referenced_column_names AS foreign
            FROM duckdb_constraints()
            WHERE database_name = current_database() AND constraint_type = 'FOREIGN KEY'
            %s
            ORDER BY table_name, constraint_name",
            $whereClause,
        );

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $params = [];
        $whereClause = '';
        if ($tableName !== null) {
            $params[] = $tableName;
            $whereClause = 'AND table_name = ?';
        }
        $sql = sprintf(
            '
            SELECT schema_name, table_name, comment
            FROM duckdb_tables()
            WHERE database_name = current_database() AND NOT internal
            %s',
            $whereClause,
        );

        $tableOptions = [];
        foreach ($this->connection->iterateAssociative($sql, $params) as $row) {
            $tableOptions[$this->_getPortableTableDefinition($row)] = ['comment' => $row['comment']];
        }

        return $tableOptions;
    }
}
