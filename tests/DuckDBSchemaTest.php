<?php

namespace DuckDb\DBAL\Tests;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use DuckDb\DBAL\Driver;
use Doctrine\DBAL\Types\Types;
use DuckDb\DBAL\Schema\DuckDBTable;
use PHPUnit\Framework\Assert;
use Stringable;

final class DuckDBSchemaTest extends TestCase
{
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
