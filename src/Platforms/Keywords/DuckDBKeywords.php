<?php

namespace DuckDb\DbalDuckdb\Platforms\Keywords;

use Doctrine\DBAL\Platforms\Keywords\KeywordList;

/**
 * DuckDB Keywordlist.
 *
 * @deprecated
 */
class DuckDBKeywords extends KeywordList
{
    /**
     * {@inheritDoc}
     */
    protected function getKeywords(): array
    {
        return [];
    }
}
