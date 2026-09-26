<?php

namespace Doctrine\DBAL\Schema\Metadata {
    /* Polyfill for doctrine/dbal < 4.5 */
    if (! class_exists(UniqueConstraintColumnMetadataRow::class, true)) {
        final readonly class UniqueConstraintColumnMetadataRow
        {
            public function __construct(
                public ?string $schemaName,
                public string $tableName,
                public int|string|null $id,
                public ?string $name,
                public string $columnName
            ) {}
        }
    }
}
