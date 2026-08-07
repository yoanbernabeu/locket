<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use App\Enum\ConversationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function findOneById(Uuid $id): ?Conversation
    {
        return $this->find($id);
    }

    public function findGlobalGroup(): ?Conversation
    {
        return $this->findOneBy(['type' => ConversationType::Group]);
    }

    public function findOneByDirectKey(string $directKey): ?Conversation
    {
        return $this->findOneBy(['directKey' => $directKey]);
    }

    /**
     * @return list<Conversation>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->join(ConversationMember::class, 'cm', 'WITH', 'cm.conversation = c')
            ->andWhere('cm.user = :user')
            ->andWhere('cm.leftAt IS NULL')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
