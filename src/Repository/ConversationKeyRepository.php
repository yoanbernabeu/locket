<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationKey;
use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<ConversationKey>
 */
class ConversationKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationKey::class);
    }

    /**
     * Toutes les enveloppes d'une époque, ordonnées par identifiant d'appareil.
     * L'ordre est celui du digest signé côté client : il doit être canonique.
     *
     * @return list<ConversationKey>
     */
    public function findByEpoch(Conversation $conversation, int $epoch): array
    {
        return $this->createQueryBuilder('k')
            ->join('k.recipientDevice', 'd')
            ->andWhere('k.conversation = :conversation')
            ->andWhere('k.epoch = :epoch')
            // L'UUID doit être lié par son type Doctrine : passer l'entité laisse
            // DBAL comparer une valeur binaire à sa représentation textuelle, et la
            // requête ne remonte alors jamais rien.
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->setParameter('epoch', $epoch)
            ->orderBy('d.deviceId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWrapFor(Conversation $conversation, int $epoch, Device $device): ?ConversationKey
    {
        return $this->findOneBy([
            'conversation' => $conversation,
            'epoch' => $epoch,
            'recipientDevice' => $device,
        ]);
    }

    public function epochExists(Conversation $conversation, int $epoch): bool
    {
        return 0 < (int) $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->andWhere('k.conversation = :conversation')
            ->andWhere('k.epoch = :epoch')
            ->setParameter('conversation', $conversation->getId(), UuidType::NAME)
            ->setParameter('epoch', $epoch)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clés purgeables. Trois conditions cumulatives :
     *
     * 1. plus vieilles que la limite de rétention ;
     * 2. n'appartenant PAS à l'époque courante de leur conversation — sans quoi une
     *    conversation calme depuis plus de 7 jours perdrait la clé avec laquelle ses membres
     *    doivent encore écrire, et deviendrait définitivement muette (PROTOCOL.md §4) ;
     * 3. plus aucun message vivant à cette époque. L'âge de la clé et celui des messages
     *    qu'elle déchiffre ne coïncident pas : une clé posée le lundi sert encore un message
     *    écrit le samedi, qui vivra jusqu'au samedi suivant. Purger sur le seul âge de la clé
     *    rendrait illisibles des messages encore présents en base.
     *
     * L'appelant doit avoir supprimé les messages expirés AVANT d'appeler cette méthode, sans
     * quoi la troisième condition retarde d'un tour la purge des clés concernées.
     *
     * @return list<ConversationKey>
     */
    public function findPurgeable(\DateTimeImmutable $olderThan): array
    {
        return $this->createQueryBuilder('k')
            ->join('k.conversation', 'c')
            ->andWhere('k.createdAt < :limit')
            ->andWhere('k.epoch <> c.currentEpoch')
            ->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\Message m WHERE m.conversation = c AND m.keyEpoch = k.epoch)')
            ->setParameter('limit', $olderThan)
            ->getQuery()
            ->getResult();
    }
}
