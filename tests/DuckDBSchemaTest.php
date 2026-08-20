<?php

namespace DuckDb\DBAL\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidColumnType\ColumnValuesRequired;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Exception\IndexNameInvalid;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\EnumType;
use Doctrine\DBAL\Types\Types;
use DuckDb\DBAL\Driver;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use DuckDb\DBAL\Schema\DuckDBType;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class DuckDBSchemaTest extends TestCase
{
    public function testCreateTable(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $sequence = $toSchema->createSequence('t1_id_seq');
        $table = $toSchema->createTable('t1');
        $table->addColumn('id', 'integer', ['default' => "nextval('t1_id_seq')", 'unsigned' => true]);
        $table->addColumn('v2', 'json', ['comment' => 'foo']);
        $table->addColumn('v3', 'guid');
        $table->addColumn('v4', 'string', ['length' => 42]);
        $table->addColumn('v5', 'date');
        $table->addColumn('v6', 'datetime');
        $table->addColumn('v7', 'datetimetz');
        $table->addColumn('v8', 'time');
        $table->addColumn('v9', 'binary', ['fixed' => true]);
        $table->addColumn('v10', 'binary');
        $table->addColumn('v11', 'enum', ['values' => ['a', 'b', 'c']]);
        $table->addColumn('v12', 'duckdb', ['columndefinition' => 'geometry']);
        $table->addColumn('v13', 'duckdb', ['columndefinition' => 'variant']);
        $table->addColumn('v14', 'smallint');
        $table->addColumn('v15', 'boolean');
        $table->addColumn('v16', 'bigint');
        $table->addColumn('v17', 'duckdb', ['columndefinition' => 'bignum']);
        $table->addColumn('v18', 'duckdb', ['columndefinition' => 'hugeint']);
        $table->addColumn('v19', 'duckdb', ['columndefinition' => 'union(num INTEGER, str VARCHAR)']);
        $table->addColumn('v20', 'duckdb', ['columndefinition' => 'map(INTEGER, VARCHAR)']);
        $table->addColumn('v21', 'duckdb', ['columndefinition' => 'struct(a STRUCT(x INTEGER), b VARCHAR)']);
        $table->addColumn('descr', 'text', ['notnull' => false]);
        $table->addUniqueConstraint(['descr']);
        $table->setPrimaryKey(['id']);
        $table->setComment('bar');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        Assert::assertSame([
            'CREATE SEQUENCE t1_id_seq START WITH 1 INCREMENT BY 1',
            "CREATE TABLE t1 (id UINTEGER DEFAULT nextval('t1_id_seq') NOT NULL, v2 JSON NOT NULL, v3 UUID NOT NULL, v4 VARCHAR NOT NULL, v5 DATE NOT NULL, v6 TIMESTAMP NOT NULL, v7 TIMESTAMP WITH TIME ZONE NOT NULL, v8 TIME NOT NULL, v9 BLOB NOT NULL, v10 BLOB NOT NULL, v11 ENUM('a', 'b', 'c') NOT NULL, v12 geometry NOT NULL, v13 variant NOT NULL, v14 SMALLINT NOT NULL, v15 BOOLEAN NOT NULL, v16 BIGINT NOT NULL, v17 bignum NOT NULL, v18 hugeint NOT NULL, v19 union(num INTEGER, str VARCHAR) NOT NULL, v20 map(INTEGER, VARCHAR) NOT NULL, v21 struct(a STRUCT(x INTEGER), b VARCHAR) NOT NULL, descr VARCHAR DEFAULT NULL, CONSTRAINT UNIQ_5B54AE374DFDC UNIQUE (descr), PRIMARY KEY (id))",
            "COMMENT ON TABLE t1 IS 'bar'",
            "COMMENT ON COLUMN t1.v2 IS 'foo'",
        ], $statements);

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function testAlterTableAddColumns(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement("CREATE TABLE t1 (i1 uinteger, v0 varchar)");

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->dropColumn('v0');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['ALTER TABLE t1 DROP COLUMN v0'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $sequence = $toSchema->createSequence('t1_id_seq');
        $table = $toSchema->getTable('t1');
        $table->addColumn('id', 'integer', ['default' => "nextval('t1_id_seq')"]);
        $table->addColumn('v2', 'json', ['comment' => 'foo']);
        $table->addColumn('v3', 'guid');
        $table->addColumn('v4', 'string', ['length' => 42, 'default' => 'foo']);
        $table->addColumn('v7', 'datetimetz', ['notnull' => false]);
        $table->addColumn('v8', 'binary', ['notnull' => false]);
        $table->addColumn('v10', 'enum', ['values' => ['a', 'b', 'c']]);
        $table->addColumn('v11', 'duckdb', ['columndefinition' => 'geometry', 'notnull' => false]);
        $table->addColumn('v12', 'duckdb', ['columndefinition' => 'variant', 'notnull' => false]);
        $table->addColumn('v14', 'boolean', ['notnull' => false]);
        $table->addColumn('v16', 'duckdb', ['columndefinition' => 'bignum', 'notnull' => false]);
        $table->addColumn('v17', 'duckdb', ['columndefinition' => 'hugeint', 'notnull' => false]);
        $table->addColumn('v18', 'duckdb', ['columndefinition' => 'union(num INTEGER, str VARCHAR)', 'notnull' => false]);
        $table->addColumn('v19', 'duckdb', ['columndefinition' => 'map(INTEGER, VARCHAR)', 'notnull' => false]);
        $table->addColumn('v20', 'duckdb', ['columndefinition' => 'struct(a STRUCT(x INTEGER), b VARCHAR)', 'notnull' => false]);
        $table->addColumn('descr', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->setComment('bar');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame([
            'CREATE SEQUENCE t1_id_seq START WITH 1 INCREMENT BY 1',
            "ALTER TABLE t1 ADD COLUMN id INTEGER DEFAULT nextval('t1_id_seq')",
            'ALTER TABLE t1 ALTER COLUMN id SET NOT NULL',
            'ALTER TABLE t1 ADD COLUMN v2 JSON DEFAULT NULL',
            'ALTER TABLE t1 ALTER COLUMN v2 SET NOT NULL',
            "COMMENT ON COLUMN t1.v2 IS 'foo'",
            'ALTER TABLE t1 ADD COLUMN v3 UUID DEFAULT NULL',
            'ALTER TABLE t1 ALTER COLUMN v3 SET NOT NULL',
            "ALTER TABLE t1 ADD COLUMN v4 VARCHAR DEFAULT 'foo'",
            'ALTER TABLE t1 ALTER COLUMN v4 SET NOT NULL',
            'ALTER TABLE t1 ADD COLUMN v7 TIMESTAMP WITH TIME ZONE DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v8 BLOB DEFAULT NULL',
            "ALTER TABLE t1 ADD COLUMN v10 ENUM('a', 'b', 'c') DEFAULT NULL",
            'ALTER TABLE t1 ALTER COLUMN v10 SET NOT NULL',
            'ALTER TABLE t1 ADD COLUMN v11 geometry DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v12 variant DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v14 BOOLEAN DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v16 bignum DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v17 hugeint DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v18 union(num INTEGER, str VARCHAR) DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v19 map(INTEGER, VARCHAR) DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v20 struct(a STRUCT(x INTEGER), b VARCHAR) DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN descr VARCHAR DEFAULT NULL',
            "COMMENT ON TABLE t1 IS 'bar'",
            'ALTER TABLE t1 ADD PRIMARY KEY (id)',
        ], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->addColumn('v14_2', 'boolean', ['notnull' => false]);
        $table->dropColumn('v14');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['ALTER TABLE t1 RENAME COLUMN v14 TO v14_2'], $statements);
    }

    public function testDropTableKeepsAutoincrementSequence(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE SEQUENCE t1_id_seq');

        $table = $schemaManager->introspectSchema()->createTable('t1');
        $table->addColumn('id', Types::INTEGER, ['default' => "nextval('t1_id_seq')"]);
        $table->setPrimaryKey(['id']);
        $schemaManager->createTable($table);
        Assert::assertCount(1, $schemaManager->introspectSequences());
        Assert::assertCount(1, $schemaManager->introspectTableNames());
        $schemaManager->dropTable('main.t1');
        Assert::assertCount(1, $schemaManager->introspectSequences());
        Assert::assertSame([], $schemaManager->introspectTableNames());

        $plain = $schemaManager->introspectSchema()->createTable('t2');
        $plain->addColumn('id', Types::INTEGER);
        $schemaManager->createTable($plain);
        $schemaManager->dropTable('t2');
        Assert::assertSame([], $schemaManager->introspectTableNames());
    }

    public function testTableColumnIntrospection(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $databaseNames = $schemaManager->introspectDatabaseNames();
        Assert::assertSame('memory', $databaseNames[0]->getIdentifier()->getValue());

        $connection->executeStatement('CREATE SEQUENCE t1_i1_seq');
        $sequences = $schemaManager->introspectSequences();
        Assert::assertSame('t1_i1_seq', $sequences[0]->getObjectName()->getUnqualifiedName()->getValue());

        $connection->executeStatement("CREATE TABLE t1 (
            i1 uinteger not null default nextval('t1_i1_seq') primary key,
            v1 enum('ab', 'cde') unique,
            va varchar[] not null,
            ge geometry,
            hu uhugeint,
            de decimal(12, 3),
            st struct(v VARCHAR, i INTEGER, a VARCHAR[], d DECIMAL),
            bl blob
        )");
        $connection->executeStatement("COMMENT ON COLUMN t1.i1 IS 'foo'");
        $connection->executeStatement("COMMENT ON TABLE t1 IS 'bar'");

        Assert::assertSame('bar', $schemaManager->introspectTableByUnquotedName('t1')->getComment());

        $columns = $schemaManager->introspectTableColumnsByUnquotedName('t1');
        Assert::assertSame('i1', $columns[0]->getObjectName()->getIdentifier()->getValue());
        Assert::assertTrue($columns[0]->getUnsigned());
        Assert::assertTrue($columns[0]->getNotnull());
        Assert::assertFalse($columns[0]->getAutoincrement());
        Assert::assertSame('foo', $columns[0]->getComment());
        Assert::assertInstanceOf(DuckDBType::class, $columns[0]->getType());
        Assert::assertSame('uinteger', $columns[0]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(EnumType::class, $columns[1]->getType());
        Assert::assertSame(['ab', 'cde'], $columns[1]->getValues());
        Assert::assertInstanceOf(DuckDBType::class, $columns[2]->getType());
        Assert::assertSame('varchar[]', $columns[2]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DuckDBType::class, $columns[3]->getType());
        Assert::assertSame('geometry', $columns[3]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DuckDBType::class, $columns[4]->getType());
        Assert::assertSame('uhugeint', $columns[4]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertSame(12, $columns[5]->getPrecision());
        Assert::assertSame(3, $columns[5]->getScale());
        Assert::assertInstanceOf(DuckDBType::class, $columns[6]->getType());
        Assert::assertSame('struct(v varchar, i integer, a varchar[], d decimal(18,3))', $columns[6]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(BlobType::class, $columns[7]->getType());
        Assert::assertSame('BLOB', $columns[7]->getType()->getSQLDeclaration([], new DuckDBPlatform()));
    }

    public function testIntrospection(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (v1 integer, ia integer[])');
        $connection->executeStatement("CREATE TABLE t2 (v1 integer, ia integer[] default '[42]')");

        $originalSchema = $schemaManager->introspectSchema();
        Assert::assertSame(['t1', 't2'], array_map(static fn(Table $t): string => $t->getObjectName()->getUnqualifiedName()->getValue(), $originalSchema->getTables()));

        $fromSchema = $originalSchema;
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t2');
        $table->addColumn('v2', 'duckdb', ['columndefinition' => 'varchar[]'])->setDefault('[21]');
        $table->addUniqueConstraint(['v2']); // no-op
        $table->getColumn('ia')->setType(new DuckDBType('varchar[]'));
        $table->getColumn('ia')->setNotnull(true)->setComment('foo')->setDefault(null);
        $table->getColumn('v1')->setDefault('21');
        $table->addUniqueIndex(['v1']);
        $table->setComment('bar');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        Assert::assertSame([
            "ALTER TABLE t2 ADD COLUMN v2 varchar[] DEFAULT '[21]'",
            'ALTER TABLE t2 ALTER COLUMN v2 SET NOT NULL',
            'ALTER TABLE t2 ALTER COLUMN v1 SET DEFAULT 21',
            'ALTER TABLE t2 ALTER COLUMN ia SET DATA TYPE varchar[]',
            'ALTER TABLE t2 ALTER COLUMN ia DROP DEFAULT',
            'ALTER TABLE t2 ALTER COLUMN ia SET NOT NULL',
            "COMMENT ON COLUMN t2.ia IS 'foo'",
            "COMMENT ON TABLE t2 IS 'bar'",
            'CREATE UNIQUE INDEX UNIQ_C25DFF8D6962CCB5 ON t2 (v1)',
        ], $statements);

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    public function testForeignKey(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $schema = clone $schemaManager->introspectSchema();
        $sequence = $schema->createSequence('parent_id_seq');
        $parent = $schema->createTable('parent');
        $parent->addColumn('id', Types::INTEGER, ['default' => "nextval('parent_id_seq')"]);
        $parent->setPrimaryKey(['id']);
        $statement = $connection->getDatabasePlatform()->getCreateSequenceSQL($sequence);
        $statements = $connection->getDatabasePlatform()->getCreateTableSQL($parent);
        $schemaManager->createSequence($sequence);
        $schemaManager->createTable($parent);

        Assert::assertSame('CREATE SEQUENCE parent_id_seq START WITH 1 INCREMENT BY 1', $statement);
        Assert::assertSame([
            "CREATE TABLE parent (id INTEGER DEFAULT nextval('parent_id_seq') NOT NULL, PRIMARY KEY (id))",
        ], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $sequence = $schema->createSequence('child_id_seq');
        $child = $toSchema->createTable('child');
        $child->addColumn('id', Types::INTEGER, ['default' => "nextval('child_id_seq')"]);
        $child->addColumn('parent_id', Types::INTEGER, ['notnull' => false]);
        $child->setPrimaryKey(['id']);
        $child->addForeignKeyConstraint('parent', ['parent_id'], ['id']);
        $statement = $connection->getDatabasePlatform()->getCreateSequenceSQL($sequence);
        $statements = $connection->getDatabasePlatform()->getCreateTableSQL($child);
        $schemaManager->createSequence($sequence);
        $schemaManager->createTable($child);

        Assert::assertSame('CREATE SEQUENCE child_id_seq START WITH 1 INCREMENT BY 1', $statement);
        Assert::assertSame([
            "CREATE TABLE child (id INTEGER DEFAULT nextval('child_id_seq') NOT NULL, parent_id INTEGER DEFAULT NULL, PRIMARY KEY (id), CONSTRAINT FK_22B35429727ACA70 FOREIGN KEY (parent_id) REFERENCES parent (id))",
            'CREATE INDEX IDX_22B35429727ACA70 ON child (parent_id)',
        ], $statements);

        $foreignKeys = $schemaManager->introspectTableForeignKeyConstraintsByUnquotedName('child');
        Assert::assertCount(1, $foreignKeys);
        $localColumns = [];
        foreach ($foreignKeys[0]->getReferencingColumnNames() as $columnName) {
            $localColumns[] = $columnName->getIdentifier()->getValue();
        }
        Assert::assertSame(['parent_id'], $localColumns);
        Assert::assertSame('parent', $foreignKeys[0]->getReferencedTableName()->getUnqualifiedName()->getValue());
    }

    public function testIntrospectView(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id INTEGER)');
        $connection->executeStatement('CREATE VIEW t1_view AS SELECT id FROM t1');

        $names = [];
        foreach ($schemaManager->introspectViews() as $view) {
            $names[] = $view->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t1_view'], $names);
    }

    public function testIntrospectSchemas(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $names = [];
        foreach ($schemaManager->introspectSchemaNames() as $schemaName) {
            $names[] = $schemaName->getIdentifier()->getValue();
        }
        Assert::assertSame(['main'], $names);
    }

    public function testIntrospectTables(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id INTEGER DEFAULT 42, b1 bool default true, b2 bool default false)');
        $connection->executeStatement('CREATE INDEX t1_id on t1(id)');

        $names = [];
        foreach ($schemaManager->introspectTables() as $table) {
            $names[] = $table->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t1'], $names);
    }

    public function testCreateSequence(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $sequence = $toSchema->createSequence('t1');
        $schemaManager->createSequence($sequence);

        $names = [];
        foreach ($schemaManager->introspectSequences() as $sequence) {
            $names[] = $sequence->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t1'], $names);
    }

    public function testDropTableDropIndex(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $table = $schemaManager->introspectSchema()->createTable('t1');
        $table->addColumn('id', 'integer');
        $table->addColumn('v0', 'string');
        $table->addIndex(['v0'], 'idx_v0');
        $schemaManager->createTable($table);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->dropIndex('idx_v0');
        $table->addIndex(['v0'], 'idx_v1');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['DROP INDEX idx_v0', 'CREATE INDEX idx_v1 ON t1 (v0)'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $toSchema->getTable('t1')->dropIndex('idx_v1');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['DROP INDEX idx_v1'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $toSchema->dropTable('t1');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['DROP TABLE t1'], $statements);
    }

    public function testListTables(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement("CREATE TABLE t0 (id INTEGER primary key, b1 boolean default true, b2 boolean default false)");
        $connection->executeStatement('CREATE TABLE t1 (id INTEGER, b0 integer references t0(id))');
        $connection->executeStatement('CREATE INDEX t1_id on t1(id)');

        $names = [];
        foreach ($schemaManager->listTables() as $table) {
            $names[] = $table->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t0', 't1'], $names);

        $names = $schemaManager->listTableNames();
        Assert::assertSame(['t0', 't1'], $names);
    }

    public function testListView(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);

        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE base (id INTEGER)');
        $connection->executeStatement('CREATE VIEW base_view AS SELECT id FROM base');

        $views = $schemaManager->listViews();
        $names = [];
        foreach ($views as $view) {
            $names[] = $view->getObjectName()->toString();
        }

        Assert::assertContains('main.base_view', $names);
    }

    public function testInvalidEnum(): void
    {
        $this->expectException(ColumnValuesRequired::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->createTable('t1');
        $table->addColumn('v11', 'enum', []);

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
    }

    public function testCreateForeignKey(): void
    {
        $this->expectException(NotSupported::class);

        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $schemaManager->createForeignKey(new ForeignKeyConstraint(['parent_id'], 'parent', ['id'], 'fk_parent'), 'child');
    }

    public function testDropForeignKey(): void
    {
        $this->expectException(NotSupported::class);

        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $schemaManager->dropForeignKey('fk_parent', 'child');
    }

    public function testAlterTableDropIndexedColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar, v1 varchar)');
        $connection->executeStatement('CREATE INDEX idx_v0 ON t1 (v0)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $toSchema->getTable('t1')->dropColumn('v0');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame([
            'DROP INDEX idx_v0',
            'ALTER TABLE t1 DROP COLUMN v0',
        ], $statements);
    }

    public function testAlterTableRenameIndexedColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 boolean)');
        $connection->executeStatement('CREATE INDEX idx_v0 ON t1 (v0)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->dropColumn('v0');
        $table->addColumn('v0_2', 'boolean', ['notnull' => false]);
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame([
            'DROP INDEX idx_v0',
            'ALTER TABLE t1 RENAME COLUMN v0 TO v0_2',
            'CREATE INDEX idx_v0 ON t1 (v0_2)',
        ], $statements);
    }

    public function testAlterTableModifiedIndex(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar, v1 varchar)');
        $connection->executeStatement('CREATE INDEX idx_v0 ON t1 (v0)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->dropIndex('idx_v0');
        $table->addIndex(['v0', 'v1'], 'idx_v0');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame([
            'DROP INDEX idx_v0',
            'CREATE INDEX idx_v0 ON t1 (v0, v1)',
        ], $statements);
    }

    public function testAlterTableAddIndexWithEmptyName(): void
    {
        $this->expectException(IndexNameInvalid::class);

        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $toSchema->getTable('t1')->addIndex(['v0'], '');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
    }

    public function testRenameTable(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar not null)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $toSchema->dropTable('t1');
        $table = $toSchema->createTable('t1_2');
        $table->addColumn('id', 'integer');
        $table->addColumn('v0', 'string');
        $table->setPrimaryKey(['id']);
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        Assert::assertSame(['ALTER TABLE t1 RENAME TO t1_2'], $statements);
    }

    public function testRenameTableWithoutPrimaryKey(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer not null, v0 varchar not null)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $toSchema->dropTable('t1');
        $table = $toSchema->createTable('t1_2');
        $table->addColumn('id', 'integer');
        $table->addColumn('v0', 'string');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        Assert::assertSame(['ALTER TABLE t1 RENAME TO t1_2'], $statements);
    }

    public function testRenameTableKeepsSequenceStartValue(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE SEQUENCE t1_id_seq START WITH 3');
        $connection->executeStatement('CREATE TABLE t1 (id integer primary key)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $toSchema->dropTable('t1');
        $toSchema->dropSequence('t1_id_seq');
        $toSchema->createSequence('t1_2_id_seq');
        $table = $toSchema->createTable('t1_2');
        $table->addColumn('id', 'integer');
        $table->setPrimaryKey(['id']);

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        Assert::assertSame([
            'CREATE SEQUENCE t1_2_id_seq START WITH 1 INCREMENT BY 1',
            "-- SELECT setval('t1_2_id_seq', currval('t1_id_seq'), true)",
            'DROP SEQUENCE t1_id_seq',
            'ALTER TABLE t1 RENAME TO t1_2',
        ], $statements);
    }

    public function testCreateSchema(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema   = clone $fromSchema;
        $toSchema->createSequence('foo.t1_id_seq');
        $table = $toSchema->createTable('foo.t1');
        $table->addColumn('id', 'integer');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        Assert::assertSame([
            'CREATE SCHEMA foo',
            'CREATE SEQUENCE foo.t1_id_seq START WITH 1 INCREMENT BY 1',
            'CREATE TABLE foo.t1 (id INTEGER NOT NULL)',
        ], $statements);
    }

    public function testAlterSequenceNotSupported(): void
    {
        $this->expectException(NotSupported::class);
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $fromSchema->createSequence('t1_id_seq');
        $toSchema->createSequence('t1_id_seq', 2);

        $diff = $connection->createSchemaManager()->createComparator()->compareSchemas($fromSchema, $toSchema);
        $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
    }

    public function testAlterTableRenameAutoIncrementColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE SEQUENCE t1_id_seq');
        $connection->executeStatement('CREATE TABLE t1 (id integer NOT NULL primary key, v0 boolean)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $toSchema->dropSequence('t1_id_seq');
        $toSchema->createSequence('t1_id2_seq');
        $table = $toSchema->getTable('t1');
        $table->dropColumn('id');
        $table->addColumn('id2', 'integer');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame([
            'CREATE SEQUENCE t1_id2_seq START WITH 1 INCREMENT BY 1',
            "-- SELECT setval('t1_id2_seq', currval('t1_id_seq'), true)",
            'DROP SEQUENCE t1_id_seq',
            'ALTER TABLE t1 RENAME COLUMN id TO id2',
        ], $statements);
    }

    public function testAlterTableDropAutoIncrementColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE SEQUENCE t1_id_seq');
        $connection->executeStatement('CREATE TABLE t1 (id integer NOT NULL primary key, v0 boolean)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $toSchema->dropSequence('t1_id_seq');
        $table = $toSchema->getTable('t1');
        $table->dropColumn('id');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        Assert::assertSame([
            'DROP SEQUENCE t1_id_seq',
            'ALTER TABLE t1 DROP COLUMN id',
        ], $statements);
    }

    public function testDropColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $oldTable = $schemaManager->introspectSchema()->createTable('t1');
        $oldTable->addColumn('id', Types::INTEGER);
        $oldTable->addColumn('name', Types::STRING);
        $oldTable->setPrimaryKey(['id']);

        $newTable = $schemaManager->introspectSchema()->createTable('t1');
        $newTable->addColumn('id', Types::INTEGER);
        $newTable->addColumn('name', Types::STRING);
        $newTable->setPrimaryKey(['id']);
        $newTable->dropColumn('id');

        $diff = $schemaManager->createComparator()->compareTables($oldTable, $newTable);
        Assert::assertSame(['id'], array_values(array_map(static fn(Column $column): string => $column->getName(), $diff->getDroppedColumns())));
        Assert::assertCount(1, $diff->getDroppedIndexes());
        Assert::assertTrue($diff->getDroppedIndexes()[0]->isPrimary());

        $newTable = $schemaManager->introspectSchema()->createTable('t1');
        $newTable->addColumn('id', Types::INTEGER);
        $newTable->addColumn('name', Types::STRING);
        $newTable->setPrimaryKey(['id']);
        $newTable->dropColumn('name');

        $diff = $schemaManager->createComparator()->compareTables($oldTable, $newTable);
        Assert::assertSame([], $diff->getDroppedIndexes());
    }

    public function testGetCreateDatabaseSQL(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();
        $schemaManager->createDatabase(sys_get_temp_dir() . '/db1.duckdb');
        Assert::assertFileExists(sys_get_temp_dir() . '/db1.duckdb');
    }

    public function testGetDropDatabaseSQL(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();
        touch(sys_get_temp_dir() . '/db1.duckdb');
        $schemaManager->dropDatabase(sys_get_temp_dir() . '/db1.duckdb');
        Assert::assertFileDoesNotExist(sys_get_temp_dir() . '/db1.duckdb');
    }
}
