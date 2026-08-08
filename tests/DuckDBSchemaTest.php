<?php

namespace DuckDb\DBAL\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\EnumType;
use Doctrine\DBAL\Types\Types;
use DuckDb\DBAL\Driver;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use DuckDb\DBAL\Schema\DuckDBTable;
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
        $table = $toSchema->createTable('t1');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
        $table->addColumn('v2', 'json', ['comment' => 'foo']);
        $table->addColumn('v3', 'guid');
        $table->addColumn('v4', 'string', ['length' => 42]);
        $table->addColumn('v7', 'datetimetz');
        $table->addColumn('v8', 'binary');
        $table->addColumn('v10', 'enum', ['values' => ['a', 'b', 'c']]);
        $table->addColumn('v11', 'geometry');
        $table->addColumn('v12', 'variant');
        $table->addColumn('v14', 'boolean');
        $table->addColumn('v16', 'bignum');
        $table->addColumn('v17', 'hugeint');
        $table->addColumn('v18', 'union(num INTEGER, str VARCHAR)');
        $table->addColumn('v19', 'map(INTEGER, VARCHAR)');
        $table->addColumn('v20', 'struct(a STRUCT(x INTEGER), b VARCHAR)');
        $table->addColumn('descr', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->setComment('bar');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        Assert::assertSame([
            'CREATE SEQUENCE IF NOT EXISTS t1_id_seq',
            "CREATE TABLE t1 (id UINTEGER DEFAULT nextval('t1_id_seq') NOT NULL, v2 JSON NOT NULL, v3 UUID NOT NULL, v4 VARCHAR NOT NULL, "
            . "v7 TIMESTAMP WITH TIME ZONE NOT NULL, v8 BLOB NOT NULL, v10 ENUM('a', 'b', 'c') NOT NULL, v11 geometry NOT NULL, v12 variant NOT NULL, "
            . "v14 BOOLEAN NOT NULL, v16 bignum NOT NULL, v17 hugeint NOT NULL, v18 union(num INTEGER, str VARCHAR) NOT NULL, v19 map(INTEGER, VARCHAR) NOT NULL, "
            . "v20 struct(a STRUCT(x INTEGER), b VARCHAR) NOT NULL, descr VARCHAR DEFAULT NULL, PRIMARY KEY (id))",
            "COMMENT ON TABLE t1 IS 'bar'",
            "COMMENT ON COLUMN t1.v2 IS 'foo'",
        ], $statements);
    }

    public function testAlterTableAddColumns(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement("CREATE TABLE t1 (i1 uinteger)");

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t1');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('v2', 'json', ['comment' => 'foo']);
        $table->addColumn('v3', 'guid');
        $table->addColumn('v4', 'string', ['length' => 42]);
        $table->addColumn('v7', 'datetimetz', ['notnull' => false]);
        $table->addColumn('v8', 'binary', ['notnull' => false]);
        $table->addColumn('v10', 'enum', ['values' => ['a', 'b', 'c']]);
        $table->addColumn('v11', 'geometry', ['notnull' => false]);
        $table->addColumn('v12', 'variant', ['notnull' => false]);
        $table->addColumn('v14', 'boolean', ['notnull' => false]);
        $table->addColumn('v16', 'bignum', ['notnull' => false]);
        $table->addColumn('v17', 'hugeint', ['notnull' => false]);
        $table->addColumn('v18', 'union(num INTEGER, str VARCHAR)', ['notnull' => false]);
        $table->addColumn('v19', 'map(INTEGER, VARCHAR)', ['notnull' => false]);
        $table->addColumn('v20', 'struct(a STRUCT(x INTEGER), b VARCHAR)', ['notnull' => false]);
        $table->addColumn('descr', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->setComment('bar');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        Assert::assertSame([
            'CREATE SEQUENCE IF NOT EXISTS t1_id_seq',
            "ALTER TABLE t1 ADD COLUMN id INTEGER DEFAULT nextval('t1_id_seq')",
            'ALTER TABLE t1 ALTER COLUMN id SET NOT NULL',
            'ALTER TABLE t1 ADD COLUMN v2 JSON DEFAULT NULL',
            'ALTER TABLE t1 ALTER COLUMN v2 SET NOT NULL',
            "COMMENT ON COLUMN t1.v2 IS 'foo'",
            'ALTER TABLE t1 ADD COLUMN v3 UUID DEFAULT NULL',
            'ALTER TABLE t1 ALTER COLUMN v3 SET NOT NULL',
            'ALTER TABLE t1 ADD COLUMN v4 VARCHAR DEFAULT NULL',
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
    }

    public function testDropTableDropsAutoincrementSequence(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $table = new DuckDBTable('t1');
        $table->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $schemaManager->createTable($table);
        Assert::assertCount(1, $schemaManager->introspectSequences());
        $schemaManager->dropTable('t1');
        Assert::assertSame([], $schemaManager->introspectSequences());
        Assert::assertSame([], $schemaManager->introspectTableNames());

        $plain = new DuckDBTable('t2');
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
        Assert::assertTrue($columns[0]->getAutoincrement());
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
        $connection->executeStatement('CREATE TABLE t2 (v1 integer, ia integer[])');

        $originalSchema = $schemaManager->introspectSchema();
        Assert::assertSame(['t1', 't2'], array_map(static fn(Table $t): string => $t->getObjectName()->getUnqualifiedName()->getValue(), $originalSchema->getTables()));

        $fromSchema = $originalSchema;
        $toSchema = clone $fromSchema;
        $table = $toSchema->getTable('t2');
        $table->addColumn('v2', 'varchar[]');
        $table->addUniqueConstraint(['v2']); // no-op
        $table->getColumn('ia')->setType(new DuckDBType('varchar[]'));
        $table->getColumn('ia')->setNotnull(true)->setComment('foo');
        $table->addUniqueIndex(['v1']);
        $table->renameColumn('v1', 'v3');
        $table->setComment('bar');

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        Assert::assertSame([
            'ALTER TABLE t2 ADD COLUMN v2 varchar[] DEFAULT NULL',
            'ALTER TABLE t2 ALTER COLUMN v2 SET NOT NULL',
            'ALTER TABLE t2 ALTER COLUMN ia SET DATA TYPE varchar[]',
            'ALTER TABLE t2 ALTER COLUMN ia SET NOT NULL',
            "COMMENT ON COLUMN t2.ia IS 'foo'",
            'ALTER TABLE t2 RENAME COLUMN v1 TO v3',
            "COMMENT ON TABLE t2 IS 'bar'",
            'CREATE UNIQUE INDEX UNIQ_C25DFF8D6962CCB5 ON t2 (v3)',
        ], $statements);

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }

        $fromSchema = $schemaManager->introspectSchema();
        $renamedSchema = clone $fromSchema;
        $renamedSchema->renameTable('t2', 't3');
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $renamedSchema);
        Assert::assertSame([
            'CREATE TABLE t3 (v3 INTEGER DEFAULT NULL, ia varchar[] NOT NULL, v2 varchar[] NOT NULL)',
            'CREATE UNIQUE INDEX UNIQ_C25DFF8D6962CCB5 ON t3 (v3)',
            "COMMENT ON TABLE t3 IS 'bar'",
            "COMMENT ON COLUMN t3.ia IS 'foo'",
            'DROP TABLE t2',
        ], $connection->getDatabasePlatform()->getAlterSchemaSQL($diff));
    }
}
