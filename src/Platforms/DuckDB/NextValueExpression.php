<?php

namespace DuckDb\DbalDuckdb\Platforms\DuckDB;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\DefaultExpression;

/**
 * Represents the DuckDB expression producing the next value of a sequence.
 *
 * @internal The class should be only used from within the DuckDB platform.
 */
final readonly class NextValueExpression implements DefaultExpression
{
    public function __construct(private string $sequenceName) {}

    public function toSQL(AbstractPlatform $platform): string
    {
        return 'nextval(' . $platform->quoteStringLiteral($this->sequenceName) . ')';
    }
}
