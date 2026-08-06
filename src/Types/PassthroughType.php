<?php

namespace DuckDb\DBAL\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * A type that emits its registered name verbatim as the SQL type declaration.
 *
 * Unlike regular Doctrine types it does not need to be registered with the
 * {@see Type} registry: the name is stored on the instance. This makes it a
 * safe fallback for unregistered DuckDB type names such as "varchar[]"
 * which are passed through unchanged.
 */
final class PassthroughType extends Type
{
    public function __construct(private readonly string $name) {}

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $this->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return [$this->name];
    }
}
