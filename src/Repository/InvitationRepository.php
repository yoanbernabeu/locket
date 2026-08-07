<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invitation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Invitation>
 */
class InvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invitation::class);
    }

    public function findOneByInviteIdHash(string $inviteIdHash): ?Invitation
    {
        return $this->findOneBy(['inviteIdHash' => $inviteIdHash]);
    }

    public function findOneById(Uuid $id): ?Invitation
    {
        return $this->find($id);
    }

    /**
     * @return list<Invitation>
     */
    public function findAllOrderedByCreation(): array
    {
        return $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Consommation ATOMIQUE (API.md §1) : c'est le nombre de lignes affectées qui décide,
     * pas une lecture préalable. Deux requêtes simultanées sur la même invitation ne peuvent
     * donc pas la consommer deux fois, quel que soit l'entrelacement.
     */
    public function consumeAtomically(Invitation $invitation, \DateTimeImmutable $now): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $uuidType = Type::getType('uuid');
        $platform = $connection->getDatabasePlatform();

        $affected = $connection->executeStatement(
            'UPDATE invitation SET used_at = :now WHERE id = :id AND used_at IS NULL AND revoked_at IS NULL AND expires_at > :now',
            [
                'now' => $now->format('Y-m-d H:i:s'),
                // La conversion passe par le type Doctrine : l'identifiant est stocké sous sa
                // forme binaire de 16 octets, et lier la représentation textuelle ne
                // sélectionnerait silencieusement aucune ligne.
                'id' => $uuidType->convertToDatabaseValue($invitation->getId(), $platform),
            ],
            [
                'now' => ParameterType::STRING,
                'id' => $uuidType->getBindingType(),
            ],
        );

        return 1 === $affected;
    }
}
