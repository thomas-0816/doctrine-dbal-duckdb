<?php

namespace DuckDb\DBAL\Schema;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class DuckDBType extends Type
{
    public function __construct(private readonly string $name) {}

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $this->name;
    }
}
