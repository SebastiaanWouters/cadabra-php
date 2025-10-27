<?php

namespace App\Repository;

use Cadabra\SymfonyBundle\ORM\CadabraQueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Base repository that returns CadabraQueryBuilder instead of default QueryBuilder.
 *
 * This allows repositories to use ->useCadabraCache() on query builders.
 *
 * Usage:
 *   class UserRepository extends CadabraRepository
 *   {
 *       public function findByEmail(string $email): ?User
 *       {
 *           return $this->createQueryBuilder('u')
 *               ->where('u.email = :email')
 *               ->setParameter('email', $email)
 *               ->useCadabraCache()  // Available because createQueryBuilder returns CadabraQueryBuilder
 *               ->getQuery()
 *               ->getOneOrNullResult();
 *       }
 *   }
 */
abstract class CadabraRepository extends ServiceEntityRepository
{
    /**
     * Creates a new CadabraQueryBuilder instance.
     *
     * @param string      $alias    The alias to use for the entity
     * @param string|null $indexBy  The index for the from clause
     *
     * @return CadabraQueryBuilder
     */
    public function createQueryBuilder($alias, $indexBy = null): CadabraQueryBuilder
    {
        $qb = new CadabraQueryBuilder($this->getEntityManager());

        return $qb->select($alias)
            ->from($this->getEntityName(), $alias, $indexBy);
    }
}
