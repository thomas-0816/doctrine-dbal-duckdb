<?php

namespace DuckDb\DBAL\Exception\InvalidColumnType;

use Doctrine\DBAL\Exception\InvalidColumnType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

use function sprintf;

/** @internal */
final class DuckDBFieldsRequired extends InvalidColumnType
{
    public static function new(AbstractPlatform $platform, string $baseType): self
    {
        return new self(
            sprintf(
                '%s requires fields of to be specified',
                $baseType,
            ),
        );
    }
}
