<?php

namespace DuckDb\DBAL\Schema\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use LogicException;

use function get_debug_type;
use function sprintf;

/**
 * A Doctrine type for DuckDB MAP columns.
 *
 * The key and value types of the map are passed as a single string, e.g.
 * "integer, varchar", so nested types are naturally supported.
 */
final class DuckDBMapType extends Type
{
    /**
     * @param string $fields The key and value types of the map, e.g. "integer, varchar".
     */
    public function __construct(private readonly string $fields = '') {}

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (! $platform instanceof DuckDBPlatform) {
            throw new LogicException(sprintf('Map is only supported on the DuckDB platform, %s given.', get_debug_type($platform)));
        }

        return $platform->getMapDeclarationSQL($column, $this->fields);
    }
}
