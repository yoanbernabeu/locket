<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\Device;
use App\Entity\User;
use App\Enum\DeviceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<Device>
 *
 * PIÈGE DOCTRINE : en DQL, lier une entité dont l'identifiant est un UUID produit une
 * comparaison sur la forme textuelle, alors que la colonne stocke 16 octets bruts — la
 * requête ne remonte alors silencieusement AUCUNE ligne. Tous les paramètres d'identité de ce
 * dépôt sont donc liés explicitement avec le type `uuid`.
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    public function findOneByDeviceId(string $deviceId): ?Device
    {
        return $this->findOneBy(['deviceId' => $deviceId]);
    }

    /**
     * @return list<Device>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Device>
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :user')
            ->andWhere('d.status = :status')
            ->setParameter('user', $user->getId(), UuidType::NAME)
            ->setParameter('status', DeviceStatus::Active)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Roster d'une conversation : appareils actifs des membres encore présents. C'est
     * exactement l'ensemble qui doit recevoir un wrap à la prochaine rotation — un membre
     * retiré en sort mécaniquement, sans traitement particulier.
     *
     * @return list<Device>
     */
    public function findActiveDevicesForConversation(Conversation $conversation): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.user', 'u')
            ->join(ConversationMember::class, 'cm', 'WITH', 'cm.user = u AND cm.conversation = :conversation')
            ->andWhere('cm.leftAt IS NULL')
            ->andWhere('u.disabledAt IS NULL')
            ->andWhere('d.status = :status')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->setParameter('status', DeviceStatus::Active)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
