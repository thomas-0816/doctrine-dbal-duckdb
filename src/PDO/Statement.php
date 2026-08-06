<?php

namespace DuckDb\Dbal\PDO;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Driver\PDO\Result;
use Doctrine\DBAL\Driver\Statement as StatementInterface;
use Doctrine\DBAL\ParameterType;
use PDO;
use PDOException;
use PDOStatement;

final class Statement implements StatementInterface
{
    /** @internal The statement can be only instantiated by its driver connection. */
    public function __construct(private readonly PDOStatement $stmt) {}

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        try {
            if (is_array($value) || is_object($value)) {
                // pdo_duckdb converts PHP arrays into DuckDB lists and PHP objects into
                // DuckDB structs natively. Binding them with PDO::PARAM_STR triggers a
                // PHP "Object could not be converted to string", so they are bound as
                // LOB instead.
                $pdoType = PDO::PARAM_LOB;
            } else {
                $pdoType = match ($type) {
                    ParameterType::NULL => PDO::PARAM_NULL,
                    ParameterType::INTEGER => PDO::PARAM_INT,
                    ParameterType::STRING, ParameterType::ASCII => PDO::PARAM_STR,
                    ParameterType::BINARY,
                    ParameterType::LARGE_OBJECT => PDO::PARAM_LOB,
                    ParameterType::BOOLEAN => PDO::PARAM_BOOL,
                };
            }

            $this->stmt->bindValue($param, $value, $pdoType);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function execute(): Result
    {
        try {
            $this->stmt->execute();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }

        return new Result($this->stmt);
    }
}
