<?php

namespace DuckDb\DbalDuckdb\PDO;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use PDO;
use PDOException;
use PDOStatement;

final class Connection implements ConnectionInterface
{
    /** @internal The connection can be only instantiated by its driver. */
    public function __construct(private readonly PDO $connection)
    {
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function exec(string $sql): int
    {
        try {
            return (int) $this->connection->exec($sql);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function getServerVersion(): string
    {
        return $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
    }

    public function prepare(string $sql): Statement
    {
        try {
            return new Statement($this->connection->prepare($sql));
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function query(string $sql): Result
    {
        try {
            /** @var PDOStatement */
            $result = $this->connection->query($sql);

            return new Result($result);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function quote(string $value): string
    {
        return $this->connection->quote($value);
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function beginTransaction(): void
    {
        try {
            $this->connection->beginTransaction();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function commit(): void
    {
        try {
            $this->connection->commit();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function rollBack(): void
    {
        try {
            $this->connection->rollBack();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function getNativeConnection(): PDO
    {
        return $this->connection;
    }
}
