<?php

namespace DuckDb\DBAL\Schema\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use LogicException;

use function get_debug_type;
use function sprintf;

/**
 * A Doctrine type for DuckDB UNION columns.
 *
 * The fields of the union are passed as a single string, e.g.
 * "a integer, b varchar", so nested types are naturally supported
 * (a field type may itself be "STRUCT(x INTEGER)").
 */
final class DuckDBUnionType extends Type
{
    /**
     * @param string $fields The fields of the union, e.g. "a integer, b varchar".
     */
    public function __construct(private readonly string $fields = '') {}

    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (! $platform instanceof DuckDBPlatform) {
            throw new LogicException(sprintf('Union is only supported on the DuckDB platform, %s given.', get_debug_type($platform)));
        }

        return $platform->getUnionDeclarationSQL($column, $this->fields);
    }
}
