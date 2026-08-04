<?php

namespace DuckDb\DbalDuckdb;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
use SensitiveParameter;
use DuckDb\DbalDuckdb\PDO\Connection;
use DuckDb\DbalDuckdb\PDO\Exception;
use DuckDb\DbalDuckdb\Platforms\DuckDBPlatform;
use PDO;
use PDOException;

/**
 * The DuckDB driver relies on the pdo_duckdb extension.
 */
final class Driver implements DriverInterface
{
    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $dsn = 'duckdb:';

        if (isset($params['path'])) {
            $dsn .= $params['path'];
        } elseif (isset($params['memory'])) {
            $dsn .= ':memory:';
        }

        $options = $params['driverOptions'] ?? [];

        try {
            if (PHP_VERSION_ID < 80400) {
                $pdo = new PDO($dsn, '', '', $options);
            } else {
                $pdo = PDO::connect($dsn, '', '', $options);
            }
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }

        return new Connection($pdo);
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return new DuckDBPlatform();
    }

    /**
     * {@inheritDoc}
     */
    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }
}
