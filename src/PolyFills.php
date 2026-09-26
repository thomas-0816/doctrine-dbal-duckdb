<?php

namespace Doctrine\DBAL\Schema\Metadata {
    /* Polyfill for doctrine/dbal < 4.5 */
    if (! class_exists(UniqueConstraintColumnMetadataRow::class, true)) {
        final readonly class UniqueConstraintColumnMetadataRow
        {
            public function __construct(
                private ?string $schemaName, // @phpstan-ignore property.onlyWritten
                private string $tableName, // @phpstan-ignore property.onlyWritten
                private int|string|null $id, // @phpstan-ignore property.onlyWritten
                private ?string $name, // @phpstan-ignore property.onlyWritten
                private string $columnName, // @phpstan-ignore property.onlyWritten
            ) {}
        }
    }
}
