<?php

namespace DuckDb\Dbal;

use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\Exception;
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
use Doctrine\DBAL\Query;

use function str_contains;

/** @internal */
final class ExceptionConverter implements ExceptionConverterInterface
{
    public function convert(Exception $exception, ?Query $query): DriverException
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'Could not open DuckDB database')) {
            return new ConnectionException($exception, $query);
        }

        if (
            str_contains($message, 'PRIMARY KEY or UNIQUE constraint violation')
        ) {
            return new UniqueConstraintViolationException($exception, $query);
        }

        if (str_contains($message, 'NOT NULL constraint failed')) {
            return new NotNullConstraintViolationException($exception, $query);
        }

        if (str_contains($message, 'Violates foreign key constraint')) {
            return new ForeignKeyConstraintViolationException($exception, $query);
        }

        if (str_contains($message, 'already exists')) {
            return new TableExistsException($exception, $query);
        }

        if (
            str_contains($message, 'does not exist!') && str_contains($message, 'Table with name')
        ) {
            return new TableNotFoundException($exception, $query);
        }

        if (
            str_contains($message, 'does not exist') || str_contains($message, 'not found in FROM clause')
        ) {
            return new InvalidFieldNameException($exception, $query);
        }

        if (str_contains($message, 'Ambiguous reference to column name')) {
            return new NonUniqueFieldNameException($exception, $query);
        }

        if (str_contains($message, 'Parser Error: syntax error')) {
            return new SyntaxErrorException($exception, $query);
        }

        if (str_contains($message, 'read-only mode')) {
            return new ReadOnlyException($exception, $query);
        }

        return new DriverException($exception, $query);
    }
}
