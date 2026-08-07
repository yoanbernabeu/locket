<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<ConversationMember>
 */
class ConversationMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationMember::class);
    }

    public function findMembership(Conversation $conversation, User $user): ?ConversationMember
    {
        return $this->findOneBy(['conversation' => $conversation, 'user' => $user]);
    }

    public function isActiveMember(Conversation $conversation, User $user): bool
    {
        return $this->findMembership($conversation, $user)?->isActive() ?? false;
    }

    /**
     * @return list<ConversationMember>
     */
    public function findActiveMembers(Conversation $conversation): array
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.user', 'u')
            ->andWhere('cm.conversation = :conversation')
            ->andWhere('cm.leftAt IS NULL')
            ->andWhere('u.disabledAt IS NULL')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->orderBy('cm.joinedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ConversationMember>
     */
    public function findActiveMembershipsOfUser(User $user): array
    {
        return $this->createQueryBuilder('cm')
            ->andWhere('cm.user = :user')
            ->andWhere('cm.leftAt IS NULL')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->getQuery()
            ->getResult();
    }
}
