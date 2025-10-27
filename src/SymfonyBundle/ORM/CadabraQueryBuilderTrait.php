<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\ORM;

use Doctrine\ORM\Query;

/**
 * Trait to add Cadabra cache control to any QueryBuilder.
 *
 * Usage in custom QueryBuilders:
 *   class MyCustomQueryBuilder extends QueryBuilder
 *   {
 *       use CadabraQueryBuilderTrait;
 *   }
 *
 * Usage:
 *   $qb->useCadabraCache()  // Opt-in to caching
 *       ->where('u.id = :id')
 *       ->getQuery()
 *       ->getResult();
 */
trait CadabraQueryBuilderTrait
{
    /** @var bool */
    private bool $cadabraUseCache = false;

    /**
     * Enable Cadabra caching for this query.
     * Injects the CADABRA:USE comment into generated SQL.
     */
    public function useCadabraCache(): self
    {
        $this->cadabraUseCache = true;
        return $this;
    }

    /**
     * Override getQuery() to inject SQL walker when use hint is set.
     *
     * IMPORTANT: If your custom QueryBuilder already overrides getQuery(),
     * call parent::getQuery() first, then add the Cadabra hint logic.
     */
    public function getQuery(): Query
    {
        // Call parent to get the Query object
        $query = parent::getQuery();

        // Inject Cadabra SQL walker if use cache is enabled
        if ($this->cadabraUseCache) {
            $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, CadabraSqlWalker::class);
            $query->setHint('cadabra.use', true);
        }

        return $query;
    }
}
