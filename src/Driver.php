<?php

namespace DuckDb\DbalDuckdb;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;
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
    public function connect(array $params): Connection
    {
        $dsn = 'duckdb:';

        if (isset($params['path'])) {
            $dsn .= $params['path'];
        } elseif (isset($params['memory'])) {
            $dsn .= ':memory:';
        } elseif (isset($params['dbname'])) {
            $dsn .= $params['dbname'];
        }

        $options = $params['driverOptions'] ?? [];

        try {
            $pdo = (PHP_VERSION_ID < 80400) ? new PDO($dsn, '', '', $options) : PDO::connect($dsn, '', '', $options);
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
