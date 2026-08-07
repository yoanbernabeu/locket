<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Message>
 */
class MessageRepository extends ServiceEntityRepository
{
    public const int DEFAULT_LIMIT = 200;
    public const int MAX_LIMIT = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Fenêtre de synchronisation incrémentale. On demande `limit + 1` lignes pour savoir s'il
     * en reste sans exécuter un COUNT sur toute la conversation.
     *
     * @return array{messages: list<Message>, hasMore: bool}
     */
    public function findSince(Conversation $conversation, int $sinceSeq, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $rows = $this->createQueryBuilder('m')
            ->andWhere('m.conversation = :conversation')
            ->andWhere('m.serverSeq > :since')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->setParameter('since', $sinceSeq)
            ->orderBy('m.serverSeq', 'ASC')
            ->setMaxResults($limit + 1)
            ->getQuery()
            ->getResult();

        $hasMore = \count($rows) > $limit;

        return [
            'messages' => \array_slice($rows, 0, $limit),
            'hasMore' => $hasMore,
        ];
    }

    public function lastSeq(Conversation $conversation): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COALESCE(MAX(m.serverSeq), 0)')
            ->andWhere('m.conversation = :conversation')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteExpired(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
