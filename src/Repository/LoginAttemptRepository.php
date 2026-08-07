<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LoginAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginAttempt>
 */
class LoginAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAttempt::class);
    }

    public function findOneByPseudo(string $normalizedPseudo): ?LoginAttempt
    {
        return $this->findOneBy(['pseudo' => $normalizedPseudo]);
    }

    /**
     * Compteurs devenus inutiles : plus aucune tentative depuis la fenêtre de décroissance et
     * aucun verrou en cours. Sans cette purge, la table grossirait au rythme des pseudos
     * inventés par un attaquant.
     *
     * @return list<LoginAttempt>
     */
    public function findStale(\DateTimeImmutable $olderThan, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.lastAttemptAt < :limit')
            ->andWhere('a.lockedUntil IS NULL OR a.lockedUntil <= :now')
            ->setParameter('limit', $olderThan)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
