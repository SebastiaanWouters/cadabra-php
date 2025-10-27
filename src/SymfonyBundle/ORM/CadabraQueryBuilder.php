<?php

declare(strict_types=1);

namespace Cadabra\SymfonyBundle\ORM;

use Doctrine\ORM\QueryBuilder;

/**
 * Extended QueryBuilder with Cadabra cache control methods.
 *
 * For projects without a custom QueryBuilder - use this directly.
 * For projects with a custom QueryBuilder - use CadabraQueryBuilderTrait instead.
 *
 * Usage:
 *   $qb->useCadabraCache()  // Opt-in to caching
 *       ->where('u.id = :id')
 *       ->setParameter('id', 1)
 *       ->getQuery()
 *       ->getResult();
 */
class CadabraQueryBuilder extends QueryBuilder
{
    use CadabraQueryBuilderTrait;
}
