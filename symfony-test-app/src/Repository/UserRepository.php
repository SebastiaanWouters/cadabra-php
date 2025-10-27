<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends CadabraRepository<User>
 */
class UserRepository extends CadabraRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->useCadabraCache()
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find users created after a specific date
     */
    public function findRecentUsers(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('u.createdAt', 'DESC')
            ->useCadabraCache()
            ->getQuery()
            ->getResult();
    }

    /**
     * Find users with their orders (for testing JOIN queries)
     */
    public function findUsersWithOrders(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->select('u', 'o')
            ->leftJoin('u.orders', 'o')
            ->setMaxResults($limit)
            ->useCadabraCache()
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total users
     */
    public function countTotal(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->useCadabraCache()
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find paginated users
     */
    public function findPaginated(int $page = 1, int $perPage = 20): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->useCadabraCache()
            ->getQuery()
            ->getResult();
    }
}
