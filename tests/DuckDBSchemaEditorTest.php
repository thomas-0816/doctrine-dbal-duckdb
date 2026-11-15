<?php

namespace DuckDb\DBAL\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\InvalidColumnType\ColumnValuesRequired;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\DBAL\Schema\UniqueConstraint;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\EnumType;
use Doctrine\DBAL\Types\TimeImmutableType;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Driver;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use DuckDb\DBAL\Schema\DuckDBType;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

final class DuckDBSchemaEditorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! method_exists(Schema::class, 'edit')) {
            self::markTestSkipped('Schema::edit() requires doctrine/dbal 4.6 or later.');
        }
    }

    public function testCreateTable(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = clone $fromSchema;

        $newTable = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setDefaultValue("nextval('t1_id_seq')")->setUnsigned(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('v2')->setTypeName('json')->setComment('foo')->create())
            ->addColumn(Column::editor()->setUnquotedName('v3')->setTypeName('guid')->create())
            ->addColumn(Column::editor()->setUnquotedName('v4')->setTypeName('string')->setLength(42)->create())
            ->addColumn(Column::editor()->setUnquotedName('v5')->setTypeName('date')->create())
            ->addColumn(Column::editor()->setUnquotedName('v6')->setTypeName('datetime')->create())
            ->addColumn(Column::editor()->setUnquotedName('v7')->setTypeName('datetimetz')->create())
            ->addColumn(Column::editor()->setUnquotedName('v8')->setTypeName('time')->create())
            ->addColumn(Column::editor()->setUnquotedName('v9')->setTypeName('duckdb')->setColumnDefinition('timetz')->create())
            ->addColumn(Column::editor()->setUnquotedName('v10')->setTypeName('binary')->setFixed(true)->create())
            ->addColumn(Column::editor()->setUnquotedName('v11')->setTypeName('binary')->create())
            ->addColumn(Column::editor()->setUnquotedName('v12')->setTypeName('enum')->setValues(['a', 'b', 'c'])->create())
            ->addColumn(Column::editor()->setUnquotedName('v13')->setTypeName('duckdb')->setColumnDefinition('geometry')->create())
            ->addColumn(Column::editor()->setUnquotedName('v14')->setTypeName('duckdb')->setColumnDefinition('variant')->create())
            ->addColumn(Column::editor()->setUnquotedName('v15')->setTypeName('smallint')->create())
            ->addColumn(Column::editor()->setUnquotedName('v16')->setTypeName('boolean')->create())
            ->addColumn(Column::editor()->setUnquotedName('v17')->setTypeName('bigint')->create())
            ->addColumn(Column::editor()->setUnquotedName('v18')->setTypeName('duckdb')->setColumnDefinition('bignum')->create())
            ->addColumn(Column::editor()->setUnquotedName('v19')->setTypeName('duckdb')->setColumnDefinition('hugeint')->create())
            ->addColumn(Column::editor()->setUnquotedName('v20')->setTypeName('duckdb')->setColumnDefinition('union(num INTEGER, str VARCHAR)')->create())
            ->addColumn(Column::editor()->setUnquotedName('v21')->setTypeName('duckdb')->setColumnDefinition('map(INTEGER, VARCHAR)')->create())
            ->addColumn(Column::editor()->setUnquotedName('v22')->setTypeName('duckdb')->setColumnDefinition('struct(a STRUCT(x INTEGER), b VARCHAR)')->create())
            ->addColumn(Column::editor()->setUnquotedName('descr')->setTypeName('text')->setNotNull(false)->create())
            ->addUniqueConstraint(UniqueConstraint::editor()->setUnquotedName('descr_unique')->setUnquotedColumnNames('descr')->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->setIsClustered(false)->create())
            ->setComment('bar')
            ->create();
        $toSchema = $toSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id_seq')->create())
            ->addTable($newTable)
            ->create();

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);

        Assert::assertSame([
            'CREATE SEQUENCE t1_id_seq START WITH 1 INCREMENT BY 1',
            "CREATE TABLE t1 (id UINTEGER DEFAULT nextval('t1_id_seq') NOT NULL, v2 JSON NOT NULL, v3 UUID NOT NULL, v4 VARCHAR NOT NULL, v5 DATE NOT NULL, v6 TIMESTAMP NOT NULL, v7 TIMESTAMP WITH TIME ZONE NOT NULL, v8 TIME NOT NULL, v9 timetz NOT NULL, v10 BLOB NOT NULL, v11 BLOB NOT NULL, v12 ENUM('a', 'b', 'c') NOT NULL, v13 geometry NOT NULL, v14 variant NOT NULL, v15 SMALLINT NOT NULL, v16 BOOLEAN NOT NULL, v17 BIGINT NOT NULL, v18 bignum NOT NULL, v19 hugeint NOT NULL, v20 union(num INTEGER, str VARCHAR) NOT NULL, v21 map(INTEGER, VARCHAR) NOT NULL, v22 struct(a STRUCT(x INTEGER), b VARCHAR) NOT NULL, descr VARCHAR DEFAULT NULL, CONSTRAINT descr_unique UNIQUE (descr), PRIMARY KEY (id))",
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
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropColumnByUnquotedName('v0');
            })
            ->create();
        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['ALTER TABLE t1 DROP COLUMN v0'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id_seq')->create())
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setDefaultValue("nextval('t1_id_seq')")->create());
                $table->addColumn(Column::editor()->setUnquotedName('v2')->setTypeName('json')->setComment('foo')->create());
                $table->addColumn(Column::editor()->setUnquotedName('v3')->setTypeName('guid')->create());
                $table->addColumn(Column::editor()->setUnquotedName('v4')->setTypeName('string')->setLength(42)->setDefaultValue('foo')->create());
                $table->addColumn(Column::editor()->setUnquotedName('v7')->setTypeName('datetimetz')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v8')->setTypeName('duckdb')->setColumnDefinition('timetz')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v9')->setTypeName('binary')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v10')->setTypeName('enum')->setValues(['a', 'b', 'c'])->create());
                $table->addColumn(Column::editor()->setUnquotedName('v11')->setTypeName('duckdb')->setColumnDefinition('geometry')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v12')->setTypeName('duckdb')->setColumnDefinition('variant')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v14')->setTypeName('boolean')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v16')->setTypeName('duckdb')->setColumnDefinition('bignum')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v17')->setTypeName('duckdb')->setColumnDefinition('hugeint')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v18')->setTypeName('duckdb')->setColumnDefinition('union(num INTEGER, str VARCHAR)')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v19')->setTypeName('duckdb')->setColumnDefinition('map(INTEGER, VARCHAR)')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('v20')->setTypeName('duckdb')->setColumnDefinition('struct(a STRUCT(x INTEGER), b VARCHAR)')->setNotNull(false)->create());
                $table->addColumn(Column::editor()->setUnquotedName('descr')->setTypeName('text')->setNotNull(false)->create());
                $table->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
                $table->setComment('bar');
            })
            ->create();
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
            'ALTER TABLE t1 ADD COLUMN v8 timetz DEFAULT NULL',
            'ALTER TABLE t1 ADD COLUMN v9 BLOB DEFAULT NULL',
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
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->addColumn(Column::editor()->setUnquotedName('v14_2')->setTypeName('boolean')->setNotNull(false)->create());
                $table->dropColumnByUnquotedName('v14');
            })
            ->create();
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

        $table = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setDefaultValue("nextval('t1_id_seq')")->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();
        $schemaManager->createTable($table);
        Assert::assertCount(1, $schemaManager->introspectSequences());
        Assert::assertCount(1, $schemaManager->introspectTableNames());
        $schemaManager->dropTable('main.t1');
        Assert::assertCount(1, $schemaManager->introspectSequences());
        Assert::assertSame([], $schemaManager->introspectTableNames());

        $plain = Table::editor()
            ->setUnquotedName('t2')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
            ->create();
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
            bl blob,
            d1 timestamp,
            d2 timestamptz,
            t1 time,
            t2 timetz
        )");
        $connection->executeStatement("COMMENT ON COLUMN t1.i1 IS 'foo'");
        $connection->executeStatement("COMMENT ON TABLE t1 IS 'bar'");

        Assert::assertSame('bar', $schemaManager->introspectTableByUnquotedName('t1')->getComment());

        $columns = $schemaManager->introspectTableColumnsByUnquotedName('t1');
        $typeProvider = method_exists($connection->getConfiguration(), 'getTypeProvider') ? $connection->getConfiguration()->getTypeProvider() : Type::getTypeRegistry();
        Assert::assertSame('i1', $columns[0]->getObjectName()->getIdentifier()->getValue());
        Assert::assertTrue($columns[0]->getUnsigned());
        Assert::assertTrue($columns[0]->getNotnull());
        Assert::assertFalse($columns[0]->getAutoincrement());
        Assert::assertSame('foo', $columns[0]->getComment());
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[0]->getTypeName()));
        Assert::assertSame('uinteger', $typeProvider->get($columns[0]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(EnumType::class, $typeProvider->get($columns[1]->getTypeName()));
        Assert::assertSame(['ab', 'cde'], $columns[1]->getValues());
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[2]->getTypeName()));
        Assert::assertSame('varchar[]', $typeProvider->get($columns[2]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[3]->getTypeName()));
        Assert::assertSame('geometry', $typeProvider->get($columns[3]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[4]->getTypeName()));
        Assert::assertSame('uhugeint', $typeProvider->get($columns[4]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertSame(12, $columns[5]->getPrecision());
        Assert::assertSame(3, $columns[5]->getScale());
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[6]->getTypeName()));
        Assert::assertSame('struct(v varchar, i integer, a varchar[], d decimal(18,3))', $typeProvider->get($columns[6]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(BlobType::class, $typeProvider->get($columns[7]->getTypeName()));
        Assert::assertSame('BLOB', $typeProvider->get($columns[7]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DateTimeImmutableType::class, $typeProvider->get($columns[8]->getTypeName()));
        Assert::assertSame('TIMESTAMP', $typeProvider->get($columns[8]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DateTimeTzImmutableType::class, $typeProvider->get($columns[9]->getTypeName()));
        Assert::assertSame('TIMESTAMP WITH TIME ZONE', $typeProvider->get($columns[9]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(TimeImmutableType::class, $typeProvider->get($columns[10]->getTypeName()));
        Assert::assertSame('TIME', $typeProvider->get($columns[10]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
        Assert::assertInstanceOf(DuckDBType::class, $typeProvider->get($columns[11]->getTypeName()));
        Assert::assertSame('time with time zone', $typeProvider->get($columns[11]->getTypeName())->getSQLDeclaration([], new DuckDBPlatform()));
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
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t2', static function (TableEditor $table): void {
                $table->addColumn(Column::editor()->setUnquotedName('v2')->setTypeName('duckdb')->setColumnDefinition('varchar[]')->setDefaultValue('[21]')->create());
                $table->addUniqueConstraint(UniqueConstraint::editor()->setUnquotedName('UNIQ_C25DFF8DF06B9D0F')->setUnquotedColumnNames('v2')->create()); // no-op
                $table->modifyColumnByUnquotedName('ia', static function (ColumnEditor $column): void {
                    $column->setTypeName('duckdb:varchar[]')->setNotNull(true)->setComment('foo')->setDefaultValue(null);
                });
                $table->modifyColumnByUnquotedName('v1', static function (ColumnEditor $column): void {
                    $column->setDefaultValue('21');
                });
                $table->addIndex(Index::editor()->setType(IndexType::UNIQUE)->setUnquotedColumnNames('v1'));
                $table->setComment('bar');
            })
            ->create();

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

        $sequence = Sequence::editor()->setUnquotedName('parent_id_seq')->create();
        $parent = Table::editor()
            ->setUnquotedName('parent')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setDefaultValue("nextval('parent_id_seq')")->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();
        $statement = $connection->getDatabasePlatform()->getCreateSequenceSQL($sequence);
        $statements = $connection->getDatabasePlatform()->getCreateTableSQL($parent);
        $schemaManager->createSequence($sequence);
        $schemaManager->createTable($parent);

        Assert::assertSame('CREATE SEQUENCE parent_id_seq START WITH 1 INCREMENT BY 1', $statement);
        Assert::assertSame([
            "CREATE TABLE parent (id INTEGER DEFAULT nextval('parent_id_seq') NOT NULL, PRIMARY KEY (id))",
        ], $statements);

        $sequence = Sequence::editor()->setUnquotedName('child_id_seq')->create();
        $child = Table::editor()
            ->setUnquotedName('child')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setDefaultValue("nextval('child_id_seq')")->create())
            ->addColumn(Column::editor()->setUnquotedName('parent_id')->setTypeName('integer')->setNotNull(false)->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->addForeignKeyConstraint(
                ForeignKeyConstraint::editor()->setUnquotedName('FK_22B35429727ACA70')->setUnquotedReferencingColumnNames('parent_id')
                    ->setUnquotedReferencedTableName('parent')->setUnquotedReferencedColumnNames('id')->create(),
            )
            ->create();
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

    public function testCreateSequence(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1')->create())
            ->create();
        $schemaManager->createSequence($toSchema->getSequence('t1'));

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

        $table = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
            ->addColumn(Column::editor()->setUnquotedName('v0')->setTypeName('string')->create())
            ->addIndex(Index::editor()->setUnquotedName('idx_v0')->setUnquotedColumnNames('v0')->create())
            ->create();
        $schemaManager->createTable($table);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropIndexByUnquotedName('idx_v0');
                $table->addIndex(Index::editor()->setUnquotedName('idx_v1')->setUnquotedColumnNames('v0')->create());
            })
            ->create();

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['DROP INDEX idx_v0', 'CREATE INDEX idx_v1 ON t1 (v0)'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropIndexByUnquotedName('idx_v1');
            })
            ->create();

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $statements = $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
        Assert::assertSame(['DROP INDEX idx_v1'], $statements);

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->dropTableByUnquotedName('t1')
            ->create();

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
        foreach ($schemaManager->introspectTables() as $table) {
            $names[] = $table->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t0', 't1'], $names);

        $names = [];
        foreach ($schemaManager->introspectTableNames() as $tableName) {
            $names[] = $tableName->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['t0', 't1'], $names);
    }

    public function testListView(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();
        $connection->executeStatement('CREATE TABLE base (id INTEGER)');
        $connection->executeStatement('CREATE VIEW base_view AS SELECT id FROM base');
        $names = [];
        foreach ($schemaManager->introspectViews() as $view) {
            $names[] = $view->getObjectName()->getUnqualifiedName()->getValue();
        }
        Assert::assertSame(['base_view'], $names);
    }

    public function testInvalidEnum(): void
    {
        $this->expectException(ColumnValuesRequired::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $schemaManager = $connection->createSchemaManager();

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->addTable(
                Table::editor()
                    ->setUnquotedName('t1')
                    ->addColumn(Column::editor()->setUnquotedName('v11')->setTypeName('enum')->create())
                    ->create(),
            )
            ->create();

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
    }

    public function testAlterTableDropIndexedColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar, v1 varchar)');
        $connection->executeStatement('CREATE INDEX idx_v0 ON t1 (v0)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropColumnByUnquotedName('v0');
            })
            ->create();
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
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropColumnByUnquotedName('v0');
                $table->addColumn(Column::editor()->setUnquotedName('v0_2')->setTypeName('boolean')->setNotNull(false)->create());
            })
            ->create();
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
        $toSchema = $fromSchema->edit()
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropIndexByUnquotedName('idx_v0');
                $table->addIndex(Index::editor()->setUnquotedName('idx_v0')->setUnquotedColumnNames('v0', 'v1')->create());
            })
            ->create();
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

    public function testRenameTable(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE TABLE t1 (id integer primary key, v0 varchar not null)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->dropTableByUnquotedName('t1')
            ->addTable(
                Table::editor()
                    ->setUnquotedName('t1_2')
                    ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
                    ->addColumn(Column::editor()->setUnquotedName('v0')->setTypeName('string')->create())
                    ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                    ->create(),
            )
            ->create();
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
        $toSchema = $fromSchema->edit()
            ->dropTableByUnquotedName('t1')
            ->addTable(
                Table::editor()
                    ->setUnquotedName('t1_2')
                    ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
                    ->addColumn(Column::editor()->setUnquotedName('v0')->setTypeName('string')->create())
                    ->create(),
            )
            ->create();
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
        $toSchema = $fromSchema->edit()
            ->dropSequenceByUnquotedName('t1_id_seq')
            ->addSequence(Sequence::editor()->setUnquotedName('t1_2_id_seq')->create())
            ->dropTableByUnquotedName('t1')
            ->addTable(
                Table::editor()
                    ->setUnquotedName('t1_2')
                    ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
                    ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
                    ->create(),
            )
            ->create();

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
        $toSchema = $fromSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id_seq', 'foo')->create())
            ->addTable(
                Table::editor()
                    ->setUnquotedName('t1', 'foo')
                    ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
                    ->create(),
            )
            ->create();

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
        $toSchema = $fromSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id_seq')->setAllocationSize(2)->create())
            ->create();
        $fromSchema = $fromSchema->edit()
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id_seq')->create())
            ->create();

        $diff = $schemaManager->createComparator()->compareSchemas($fromSchema, $toSchema);
        $connection->getDatabasePlatform()->getAlterSchemaSQL($diff);
    }

    public function testAlterTableRenameAutoIncrementColumn(): void
    {
        $connection = DriverManager::getConnection(['driverClass' => Driver::class, 'memory' => true]);
        $schemaManager = $connection->createSchemaManager();

        $connection->executeStatement('CREATE SEQUENCE t1_id_seq');
        $connection->executeStatement('CREATE TABLE t1 (id integer NOT NULL primary key, v0 boolean)');

        $fromSchema = $schemaManager->introspectSchema();
        $toSchema = $fromSchema->edit()
            ->dropSequenceByUnquotedName('t1_id_seq')
            ->addSequence(Sequence::editor()->setUnquotedName('t1_id2_seq')->create())
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropPrimaryKeyConstraint();
                $table->dropColumnByUnquotedName('id');
                $table->addColumn(Column::editor()->setUnquotedName('id2')->setTypeName('integer')->create());
            })
            ->create();
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
        $toSchema = $fromSchema->edit()
            ->dropSequenceByUnquotedName('t1_id_seq')
            ->modifyTableByUnquotedName('t1', static function (TableEditor $table): void {
                $table->dropPrimaryKeyConstraint();
                $table->dropColumnByUnquotedName('id');
            })
            ->create();
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

        $oldTable = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
            ->addColumn(Column::editor()->setUnquotedName('name')->setTypeName('string')->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->create();

        $newTable = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
            ->addColumn(Column::editor()->setUnquotedName('name')->setTypeName('string')->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->dropPrimaryKeyConstraint()
            ->dropColumnByUnquotedName('id')
            ->create();

        $diff = $schemaManager->createComparator()->compareTables($oldTable, $newTable);
        Assert::assertSame(['id'], array_values(array_map(static fn(Column $column): string => $column->getName(), $diff->getDroppedColumns())));
        Assert::assertCount(1, $diff->getDroppedIndexes());
        Assert::assertTrue($diff->getDroppedIndexes()[0]->isPrimary());

        $newTable = Table::editor()
            ->setUnquotedName('t1')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())
            ->addColumn(Column::editor()->setUnquotedName('name')->setTypeName('string')->create())
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->dropColumnByUnquotedName('name')
            ->create();

        $diff = $schemaManager->createComparator()->compareTables($oldTable, $newTable);
        Assert::assertSame([], $diff->getDroppedIndexes());
    }
}
