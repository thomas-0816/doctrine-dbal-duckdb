<?php

namespace DuckDb\DBAL\Schema\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use LogicException;

use function get_debug_type;
use function sprintf;

/**
 * A Doctrine type for DuckDB STRUCT columns.
 *
 * The fields of the struct are passed as a single string, e.g.
 * "id integer, name varchar", so nested structs are naturally supported
 * (a field type may itself be "STRUCT(x INTEGER)").
 */
final class DuckDBStructType extends Type
{
    /**
     * @param string $fields The fields of the struct, e.g. "id integer, name varchar".
     */
    public function __construct(private readonly string $fields = '') {}

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (! $platform instanceof DuckDBPlatform) {
            throw new LogicException(sprintf('Struct is only supported on the DuckDB platform, %s given.', get_debug_type($platform)));
        }

        return $platform->getStructDeclarationSQL($column, $this->fields);
    }
}
