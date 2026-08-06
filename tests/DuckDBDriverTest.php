<?php

namespace DuckDb\DBAL\Tests;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\NonUniqueFieldNameException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\ReadOnlyException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Exception\TableExistsException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use DuckDb\DBAL\Driver;
use DuckDb\DBAL\PDO\Statement;
use Doctrine\DBAL\Driver\PDO\Exception as PdoConnectionException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use DuckDb\DBAL\Schema\DuckDBTable;
use PHPUnit\Framework\Assert;
use PDO;
use PDOException;
use PDOStatement;
use Stringable;

final class DuckDBDriverTest extends TestCase
{
    public function testConnection(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertFalse($connection->isConnected());
        Assert::assertSame(1, $connection->fetchOne("SELECT 1"));
        Assert::assertTrue($connection->isConnected());
        $connection->close();

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:', 'driverOptions' => [PDO::DUCKDB_ATTR_CONFIG => ['TimeZone' => 'Europe/Berlin']]];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertFalse($connection->isConnected());
        Assert::assertSame('Europe/Berlin', $connection->fetchOne("SELECT value FROM duckdb_settings() where name = 'TimeZone'"));
        Assert::assertTrue($connection->isConnected());
        $connection->close();

        $tmpFile = tempnam(sys_get_temp_dir(), 'connect') . '.duckdb';
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => $tmpFile];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertSame(1, $connection->fetchOne("SELECT 1"));
        Assert::assertFileExists($tmpFile);
        @unlink($tmpFile);

        $connectionParams = ['driverClass' => Driver::class, 'memory' => true];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertFalse($connection->isConnected());
        Assert::assertSame(1, $connection->fetchOne("SELECT 1"));

        $tmpFile = tempnam(sys_get_temp_dir(), 'connect') . '.duckdb';
        $connectionParams = ['driverClass' => Driver::class, 'path' => $tmpFile];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertSame(1, $connection->fetchOne("SELECT 1"));
        Assert::assertFileExists($tmpFile);
        @unlink($tmpFile);

        if (PHP_OS_FAMILY !== 'Windows') {
            $dsnParser = new DsnParser(['duckdb' => Driver::class]);
            $params = $dsnParser->parse('duckdb://_//tmp/test.duckdb');
            $connection = DriverManager::getConnection($params);
            Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
            Assert::assertFalse($connection->isConnected());
            Assert::assertSame(1, $connection->fetchOne("SELECT 1"));
            Assert::assertFileExists('/tmp/test.duckdb');
            @unlink('/tmp/test.duckdb');
        }

        $dsnParser = new DsnParser(['duckdb' => Driver::class]);
        $connectionParams = $dsnParser->parse('duckdb::memory:');
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertInstanceOf(DuckDBPlatform::class, $connection->getDatabasePlatform());
        Assert::assertFalse($connection->isConnected());
        Assert::assertSame(1, $connection->fetchOne("SELECT 1"));
    }

    public function testDataTypes(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertSame('Hello DuckDB! 🐘 💓 🦆', $connection->fetchOne("SELECT 'Hello DuckDB! 🐘 💓 🦆'"));
        Assert::assertTrue($connection->fetchOne("SELECT true"));
        Assert::assertNull($connection->fetchOne("SELECT null"));
        Assert::assertSame(['foo', 'bar'], $connection->fetchOne("SELECT ['foo', 'bar']"));
        Assert::assertSame(['k1' => 'foo', 'k2' => 'bar'], $connection->fetchOne("SELECT {'k1': 'foo', 'k2': 'bar'}"));
        Assert::assertSame(['k1' => 10, 'k2' => 20], $connection->fetchOne("SELECT MAP {'k1': 10, 'k2': 20}"));
        Assert::assertSame('POINT (30 10)', $connection->fetchOne("select 'POINT (30 10)'::GEOMETRY"));
        Assert::assertSame('92233720368547758071', $connection->fetchOne("SELECT 92233720368547758071"));
        Assert::assertNan($connection->fetchOne("SELECT 0/0"));
        Assert::assertInfinite($connection->fetchOne("SELECT 1/0"));
    }

    public function testQuote(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertSame('\'"foo" \'\'bar\'\' \"foo2\" \\\'\'bar2\\\'\' öäü 🐘\'', $connection->quote('"foo" \'bar\' \"foo2\" \\\'bar2\\\' öäü 🐘'));
    }

    public function testTransaction(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t1 VALUES (42)');
        Assert::assertSame(42, $connection->fetchOne('select i1 from t1'));
        $connection->rollBack();
        Assert::assertSame(false, $connection->fetchOne('select i1 from t1'));

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t1 VALUES (42)');
        Assert::assertSame(42, $connection->fetchOne('select i1 from t1'));
        $connection->commit();
        Assert::assertSame(42, $connection->fetchOne('select i1 from t1'));

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $config = new Configuration();
        $config->setAutoCommit(false);
        $connection = DriverManager::getConnection($connectionParams, $config);
        $connection->executeStatement('CREATE TABLE t2 (i1 integer)');
        $connection->commit();
        $connection->executeStatement('INSERT INTO t2 VALUES (42)');
        $connection->rollBack();
        $connection->executeStatement('INSERT INTO t2 VALUES (42)');
        $connection->rollBack();
        $connection->executeStatement('INSERT INTO t2 VALUES (42)');
        $connection->commit();
        Assert::assertSame(1, $connection->fetchOne('select count(*) from t2'));
    }

    public function testResult(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');
        $connection->executeStatement('INSERT INTO t1 VALUES (?)', [42]);
        Assert::assertSame([42], $connection->fetchNumeric('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame(['i1' => 42], $connection->fetchAssociative('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame(42, $connection->fetchOne('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame([[42]], $connection->fetchAllNumeric('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame([['i1' => 42]], $connection->fetchAllAssociative('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame([42], $connection->fetchFirstColumn('SELECT * FROM t1 WHERE i1 = ?', [42]));
        Assert::assertSame(['foo' => 'bar'], $connection->fetchAllKeyValue("SELECT 'foo', 'bar'"));
        Assert::assertSame([1 => ['b' => 'a', 'd' => 'c']], $connection->fetchAllAssociativeIndexed("SELECT 1, 'a' as b, 'c' as d"));
        Assert::assertSame([['b' => 1], ['b' => 2]], iterator_to_array($connection->iterateAssociative('SELECT * FROM VALUES ((1)), ((2)) a(b)')));

        $connection->executeStatement('CREATE TABLE t2 (i1 varchar[])');
        $connection->executeStatement('INSERT INTO t2 VALUES (?)', [['foo', 'bar']]);
        $connection->executeStatement('INSERT INTO t2 VALUES (?)', [['baz']]);
        Assert::assertSame([['i1' => ['foo', 'bar']]], $connection->fetchAllAssociative('SELECT * FROM t2 LIMIT 1'));
        Assert::assertSame([['i1' => ['foo', 'bar']]], $connection->fetchAllAssociative("SELECT * FROM t2 WHERE i1 = ?", [['foo', 'bar']]));

        $connection->executeStatement('CREATE TABLE t3 (s1 struct(v VARCHAR, i INTEGER))');
        $connection->executeStatement('INSERT INTO t3 VALUES (?)', [['v' => 'foo', 'i' => 42]]);
        Assert::assertSame([['s1' => ['v' => 'foo', 'i' => 42]]], $connection->fetchAllAssociative('SELECT * FROM t3 LIMIT 1'));
        Assert::assertSame([['s1' => ['v' => 'foo', 'i' => 42]]], $connection->fetchAllAssociative("SELECT * FROM t3 WHERE s1 = ?", [['v' => 'foo', 'i' => 42]]));

        $connection->executeStatement('CREATE TABLE t4 (i1 integer, s1 varchar)');
        $connection->executeStatement('INSERT INTO t4 VALUES (?, ?)', [1, 'a']);
        $connection->executeStatement('INSERT INTO t4 VALUES (?, ?)', [2, 'b']);
        Assert::assertSame(1, $connection->executeStatement('UPDATE t4 SET s1 = ? WHERE s1 = ?', ['x', 'a']));

        $result = $connection->executeQuery('SELECT * FROM t4 ORDER BY i1');
        Assert::assertSame(2, $result->columnCount());
        Assert::assertSame('i1', $result->getColumnName(0));
        Assert::assertSame('s1', $result->getColumnName(1));
        Assert::assertSame([1, 'x'], $result->fetchNumeric());
        Assert::assertSame([2, 'b'], $result->fetchNumeric());
        Assert::assertFalse($result->fetchNumeric());
        $result->free();

        $data = ['b1' => true, 'v1' => 'foo', 'null1' => null, 'n2' => 42, 'v2' => 'bar', 'v3' => 'baz', 'blob1' => 'a'];
        $connection->executeStatement('CREATE TABLE t6 (b1 boolean, v1 varchar, null1 integer, n2 integer, v2 varchar, v3 varchar, blob1 blob)');
        $connection->executeStatement('INSERT INTO t6 VALUES (?, ?, ?, ?, ?, ?, ?)', array_values($data), [
            ParameterType::BOOLEAN, ParameterType::LARGE_OBJECT, ParameterType::NULL, ParameterType::INTEGER,
            ParameterType::STRING, ParameterType::ASCII, ParameterType::BINARY,
        ]);
        Assert::assertSame($data, $connection->fetchAssociative('SELECT * FROM t6'));

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:', 'driverOptions' => [PDO::DUCKDB_ATTR_CONFIG => ['TimeZone' => 'Europe/Berlin']]];
        $connection = DriverManager::getConnection($connectionParams);
        $date = new \DateTime("2011-03-05 14:00:21", new \DateTimeZone('Europe/Berlin'));
        $date2 = new \DateTime("2011-03-05 14:00:21", new \DateTimeZone('America/New_York'));
        $connection->executeStatement('CREATE TABLE t7 (d1 timestamp, d2 timestamptz)');
        $connection->executeStatement('INSERT INTO t7 VALUES (?, ?)', [$date, $date2], ['datetime', 'datetimetz']);
        $stmt = $connection->prepare('SELECT * FROM t7 WHERE d1 >= ?');
        $stmt->bindValue(1, $date, 'datetime');
        Assert::assertSame(['2011-03-05 14:00:21', '2011-03-05 20:00:21+01'], $stmt->executeQuery()->fetchNumeric());

        $connection->executeStatement('CREATE TABLE t8 (i1 integer)');
        $connection->executeStatement('INSERT INTO t8 VALUES (1), (2)');
        Assert::assertSame([1, 2], $connection->fetchFirstColumn('SELECT * FROM t8 WHERE i1 IN (?)', [[1, 2, 3]], [ArrayParameterType::INTEGER]));
        Assert::assertSame([1, 2], $connection->fetchFirstColumn('SELECT * FROM t8 WHERE i1 IN cast(? as int[])', [[1, 2, 3]]));

        $connection->executeStatement('CREATE TABLE t9 (v1 varchar)');
        $connection->executeStatement("INSERT INTO t9 VALUES ('foo'), ('bar')");

        $statement = $connection->prepare('SELECT * FROM t9 WHERE v1 = ?');
        $statement->bindValue(1, 'foo');
        Assert::assertSame([['foo']], $statement->executeQuery()->fetchAllNumeric());

        $statement = $connection->prepare('SELECT * FROM t9 WHERE v1 = :user');
        $statement->bindValue('user', 'foo');
        Assert::assertSame([['foo']], $statement->executeQuery()->fetchAllNumeric());
    }

    public function testInsertUpdateDelete(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);

        $connection->executeStatement('CREATE TABLE user (id integer, username varchar)');

        $connection->insert('user', ['id' => 1, 'username' => 'foo']);
        Assert::assertSame('foo', $connection->fetchOne('SELECT username from user where id = 1'));

        $connection->update('user', ['username' => 'bar'], ['id' => 1]);
        Assert::assertSame('bar', $connection->fetchOne('SELECT username from user where id = 1'));

        $connection->delete('user', ['id' => 1]);
        Assert::assertSame(false, $connection->fetchOne('SELECT username from user where id = 1'));
    }

    public function testLastInsertId(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');
        $connection->executeStatement('INSERT INTO t1 VALUES (1)');
        Assert::assertSame(0, $connection->lastInsertId());

        $connection->executeStatement('CREATE SEQUENCE seq_t2_i1');
        $connection->executeStatement("CREATE TABLE t2 (i1 integer not null default nextval('seq_t2_i1') primary key)");
        Assert::assertSame(1, $connection->fetchOne('INSERT INTO t2 VALUES (DEFAULT) RETURNING *'));
        Assert::assertSame(2, $connection->fetchOne('INSERT INTO t2 VALUES (DEFAULT) RETURNING *'));
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

    public function testGetServerVersion(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        Assert::assertSame('v1.5.5', $connection->getServerVersion());
    }

    public function testGetNativeConnection(): void
    {
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        /** @var PDO */
        $pdo = $connection->getNativeConnection();
        Assert::assertSame('v1.5.5', $pdo->query('SELECT version()')->fetchColumn());
    }

    public function testPrepareException(): void
    {
        $this->expectException(DriverException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');
        $connection->executeStatement('INSERT INTO t1 VALUES (?, ?)', [42]);
    }

    public function testReadOnlyException(): void
    {
        $this->expectException(ReadOnlyException::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'connect') . '.duckdb';
        $connectionParams = ['driverClass' => Driver::class, 'dbname' => $tmpFile];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT 1");

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => $tmpFile, 'driverOptions' => [PDO::DUCKDB_ATTR_CONFIG => ['access_mode' => 'read_only']]];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement("CREATE TABLE t1 (t1 integer)");
    }

    public function testConnectionExceptionInvalidFile(): void
    {
        $this->expectException(ConnectionException::class);

        $tmpFile = tempnam(sys_get_temp_dir(), 'connect') . '.duckdb';
        file_put_contents($tmpFile, 'invalid');

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => $tmpFile];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT foo bar");
    }

    public function testConnectionExceptionInvalidFileName(): void
    {
        $this->expectException(ConnectionException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => '/invalid/invalid'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT foo bar");
    }

    public function testDriverException(): void
    {
        $this->expectException(DriverException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT foo bar");
    }

    public function testSyntaxErrorException(): void
    {
        $this->expectException(SyntaxErrorException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne('invalid');
    }

    public function testTableNotFoundException(): void
    {
        $this->expectException(TableNotFoundException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne('SELECT * FROM invalid');
    }

    public function testNonUniqueFieldNameException(): void
    {
        $this->expectException(NonUniqueFieldNameException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT id FROM (VALUES (1)) table1(id), (VALUES (1)) table2(id)");
    }

    public function testInvalidFieldNameException(): void
    {
        $this->expectException(InvalidFieldNameException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->fetchOne("SELECT invalid FROM (VALUES (1)) table1(id)");
    }

    public function testTableExistsException(): void
    {
        $this->expectException(TableExistsException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');
        $connection->executeStatement('CREATE TABLE t1 (i1 integer)');
    }

    public function testNotNullConstraintViolationException(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer NOT NULL)');
        $connection->executeStatement('INSERT INTO t1 VALUES (null)');
    }

    public function testUniqueConstraintViolationException(): void
    {
        $this->expectException(UniqueConstraintViolationException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE t1 (i1 integer NOT NULL PRIMARY KEY)');
        $connection->executeStatement('INSERT INTO t1 VALUES (1), (1)');
    }

    public function testForeignKeyConstraintViolationException(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $connectionParams = ['driverClass' => Driver::class, 'dbname' => ':memory:'];
        $connection = DriverManager::getConnection($connectionParams);
        $connection->executeStatement('CREATE TABLE parent (id integer PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE child (id integer, parent_id integer REFERENCES parent(id))');
        $connection->executeStatement('INSERT INTO child VALUES (1, 1)');
    }

    public function testStatementBindValueException(): void
    {
        $this->expectException(PdoConnectionException::class);

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects(self::once())
            ->method('bindValue')
            ->willThrowException(new PDOException('bind failed'));
        $statement = new Statement($stmt);
        $statement->bindValue(1, 'foo', ParameterType::STRING);
    }

    public function testStatementExecuteException(): void
    {
        $this->expectException(PdoConnectionException::class);

        $pdo = new PDO('duckdb::memory:');
        $pdo->exec('CREATE TABLE t (a varchar, b varchar)');
        $statement = new Statement($pdo->prepare('INSERT INTO t VALUES (?, ?)'));
        $statement->bindValue(1, 'onlyone', ParameterType::STRING);
        $statement->execute();
    }

    public function testBeginTransactionException(): void
    {
        $this->expectException(PdoConnectionException::class);

        $connection = (new Driver())->connect(['dbname' => ':memory:']);
        $connection->beginTransaction();
        $connection->beginTransaction();
    }

    public function testCommitException(): void
    {
        $this->expectException(PdoConnectionException::class);

        $connection = (new Driver())->connect(['dbname' => ':memory:']);
        $connection->commit();
    }

    public function testRollBackException(): void
    {
        $this->expectException(PdoConnectionException::class);

        $connection = (new Driver())->connect(['dbname' => ':memory:']);
        $connection->rollBack();
    }

    private function setStdOutLogger(Configuration $config): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                foreach ($context as $key => $value) {
                    $message = str_replace('{' . $key . '}', json_encode($value), $message);
                }
                fwrite(STDOUT, $message . PHP_EOL);
            }
        };

        $config->setMiddlewares([new Middleware($logger)]);
    }
}
