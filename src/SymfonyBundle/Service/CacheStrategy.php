<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\Service;

/**
 * Determines whether to cache queries based on server analysis.
 * Uses server analysis results (NOT raw SQL parsing).
 *
 * Note: This is called AFTER the query has been marked for caching
 * via the CADABRA:USE comment. This provides additional filtering.
 */
class CacheStrategy
{
    /**
     * @param array<string, mixed> $config Configuration array (reserved for future use)
     */
    public function __construct(array $config = [])
    {
        // Config reserved for future strategy options
    }

    /**
     * Determine if a query should be cached based on server analysis.
     *
     * @param array<mixed> $analysis Analysis result from Cadabra server
     * @return bool True if should cache
     */
    public function shouldCache(array $analysis): bool
    {
        // Only cache SELECT queries
        return ($analysis['operation_type'] ?? '') === 'read';
    }

    /**
     * Get the primary table from the query.
     *
     * @param array<mixed> $analysis
     * @return string|null
     */
    public function getPrimaryTable(array $analysis): ?string
    {
        $cacheKey = $analysis['cache_key'] ?? [];
        $tables = $cacheKey['tables'] ?? [];

        if (empty($tables)) {
            return null;
        }

        // First table is the primary table (FROM clause)
        return $tables[0]['table'] ?? null;
    }
}
