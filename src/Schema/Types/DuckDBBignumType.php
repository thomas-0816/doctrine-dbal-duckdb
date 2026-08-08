<?php

namespace DuckDb\DBAL\Schema\Types;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use DuckDb\DBAL\Platforms\DuckDBPlatform;
use LogicException;

use function get_debug_type;
use function sprintf;

/**
 * A Doctrine type for DuckDB BIGNUM columns.
 *
 * Unlike a struct, a bignum column has no fields, so the declaration is
 * fixed.
 */
final class DuckDBBignumType extends Type
{
    /**
     * {@inheritDoc}
     */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if (! $platform instanceof DuckDBPlatform) {
            throw new LogicException(sprintf('Bignum is only supported on the DuckDB platform, %s given.', get_debug_type($platform)));
        }

        return $platform->getBignumDeclarationSQL();
    }
}
