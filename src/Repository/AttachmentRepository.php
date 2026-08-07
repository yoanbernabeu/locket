<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Attachment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Attachment>
 */
class AttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Attachment::class);
    }

    public function findOneByMediaId(string $mediaId): ?Attachment
    {
        return $this->findOneBy(['mediaId' => $mediaId]);
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return list<Attachment>
     */
    public function findByMediaIds(array $mediaIds): array
    {
        if ([] === $mediaIds) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.mediaId IN (:ids)')
            ->setParameter('ids', $mediaIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Volume déposé par un membre et pas encore rattaché à une conversation : c'est la seule
     * part de son stockage qu'il contrôle librement, donc la seule à plafonner.
     */
    public function unlinkedBytesOf(User $owner): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.bytes), 0)')
            ->andWhere('a.owner = :owner')
            ->andWhere('a.conversation IS NULL')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Un média expiré, ou orphelin (jamais rattaché à un message) au-delà de son délai de
     * grâce, n'a plus de raison d'exister : personne ne détient plus la clé pour le lire.
     *
     * @return list<Attachment>
     */
    public function findExpired(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
