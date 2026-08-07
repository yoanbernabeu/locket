<?php

declare(strict_types=1);

namespace App\Service\Key;

use App\Entity\Conversation;
use App\Entity\ConversationKey;
use App\Entity\Device;
use App\Exception\ConflictException;
use App\Exception\InvalidPayloadException;
use App\Repository\ConversationKeyRepository;
use App\Repository\DeviceRepository;
use App\Serializer\RosterPresenter;
use App\Service\Realtime\RealtimePublisher;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Dépôt d'un bundle de rotation d'époque (PROTOCOL.md §4, API.md §2).
 */
final class ConversationKeyService
{
    /**
     * L'époque voyage sur 4 octets non signés dans l'en-tête LK1M et dans l'AAD des wraps :
     * au-delà, le nombre déposé ne serait plus représentable dans le protocole.
     */
    public const int MAX_EPOCH = 0xFFFFFFFF;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationKeyRepository $keys,
        private readonly DeviceRepository $devices,
        private readonly RosterPresenter $rosterPresenter,
        private readonly RealtimePublisher $realtime,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param list<array{recipientDeviceId: string, wrap: string}> $wraps wraps décodés (130 octets)
     *
     * @throws ConflictException si une autre rotation a déjà pris cette époque, ou si le
     *                           roster a changé depuis la lecture faite par l'appelant
     */
    public function deposit(Conversation $conversation, int $epoch, int $memberSetVersion, array $wraps, string $attestation): void
    {
        $recipientIds = $this->assertWellFormed($epoch, $memberSetVersion, $wraps);

        // Transaction ouverte au niveau DBAL et non par EntityManager::wrapInTransaction() :
        // ce dernier FERME l'EntityManager dès qu'une exception traverse la fermeture, y
        // compris un simple 409 de concurrence, qui est pourtant un refus parfaitement
        // ordinaire. Ici, un conflit annule proprement l'UPDATE et laisse l'unité de travail
        // utilisable pour la suite de la requête.
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            /** @var list<Device> $notified */
            $notified = $this->depositInTransaction($conversation, $epoch, $memberSetVersion, $wraps, $recipientIds, $attestation);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        // C12 — publier APRÈS le commit, jamais dedans : un « key.rotated » émis à l'intérieur
        // de la transaction annoncerait aux appareils une époque qui n'existera jamais si le
        // commit échoue, et ils tourneraient à vide sur un GET de wrap introuvable.
        foreach ($notified as $device) {
            $this->realtime->publishKeyRotated($device, $conversation, $epoch);
        }
    }

    public function findWrap(Conversation $conversation, int $epoch, Device $device): ?ConversationKey
    {
        return $this->keys->findWrapFor($conversation, $epoch, $device);
    }

    /**
     * Toutes les enveloppes d'une époque.
     *
     * Le client en a besoin pour recalculer l'empreinte que couvre l'attestation :
     * sans l'ensemble exact, il ne peut pas vérifier que le bundle qu'on lui sert est
     * bien celui qui a été signé.
     *
     * @return list<ConversationKey>
     */
    public function findEpochWraps(Conversation $conversation, int $epoch): array
    {
        return $this->keys->findByEpoch($conversation, $epoch);
    }

    /**
     * Contrôles de forme, indépendants de l'état de la base.
     *
     * L'égalité `epoch === memberSetVersion` est la règle qui rend la rotation bornée
     * (PROTOCOL.md §4 : « epoch = V »). Sans elle, un seul membre déposant `epoch = 1000000`
     * fige la conversation : toute rotation ultérieure viserait une époque inférieure et
     * serait refusée, définitivement.
     *
     * @param list<array{recipientDeviceId: string, wrap: string}> $wraps
     *
     * @return list<string> identifiants d'appareils destinataires, sans doublon
     */
    private function assertWellFormed(int $epoch, int $memberSetVersion, array $wraps): array
    {
        if ($epoch < 1) {
            throw new InvalidPayloadException('Le numéro d\'époque doit être strictement positif.');
        }

        if ($epoch > self::MAX_EPOCH) {
            throw new InvalidPayloadException(\sprintf('Le numéro d\'époque ne peut pas dépasser %d.', self::MAX_EPOCH));
        }

        if ($epoch !== $memberSetVersion) {
            throw new InvalidPayloadException('L\'époque déposée doit être exactement égale au numéro de composition (memberSetVersion).');
        }

        if ([] === $wraps) {
            throw new InvalidPayloadException('Un bundle de rotation doit contenir au moins un wrap.');
        }

        $recipientIds = [];

        foreach ($wraps as $entry) {
            if (ConversationKey::WRAP_BYTES !== \strlen($entry['wrap'])) {
                throw new InvalidPayloadException(\sprintf('Un wrap LK1W doit faire %d octets.', ConversationKey::WRAP_BYTES));
            }

            $recipientIds[] = $entry['recipientDeviceId'];
        }

        // Un doublon permettrait de « remplir » le bundle sans couvrir tout le roster, et
        // heurterait de toute façon la contrainte d'unicité (conversation, epoch, device).
        if (\count($recipientIds) !== \count(array_unique($recipientIds))) {
            throw new InvalidPayloadException('Un bundle de rotation ne peut pas contenir deux wraps pour le même appareil.');
        }

        return $recipientIds;
    }

    /**
     * @param list<array{recipientDeviceId: string, wrap: string}> $wraps
     * @param list<string>                                         $recipientIds
     *
     * @return list<Device> appareils à prévenir une fois le commit acquis
     */
    private function depositInTransaction(
        Conversation $conversation,
        int $epoch,
        int $memberSetVersion,
        array $wraps,
        array $recipientIds,
        string $attestation,
    ): array {
        // C11 — l'arbitrage est fait par le nombre de lignes affectées, comme pour la
        // consommation d'invitation. Une relecture suivie d'un test en PHP ne verrouille
        // rien : deux rotations concurrentes liraient toutes deux l'ancienne époque et se
        // croiraient toutes deux légitimes. Ici, une seule des deux touche une ligne.
        if (1 !== $this->claimEpoch($conversation, $epoch, $memberSetVersion)) {
            $this->entityManager->refresh($conversation);

            throw new ConflictException(
                $conversation->getCurrentEpoch() >= $epoch
                    ? 'Une époque supérieure ou égale a déjà été publiée.'
                    : 'La composition de la conversation a changé depuis la lecture du roster.',
                $this->rosterPresenter->present($conversation),
            );
        }

        // L'entité doit refléter l'époque que l'UPDATE vient de revendiquer : elle a été
        // écrite hors de l'unité de travail, qui l'ignore encore.
        $this->entityManager->refresh($conversation);

        $roster = [];

        foreach ($this->devices->findActiveDevicesForConversation($conversation) as $device) {
            $roster[$device->getDeviceId()] = $device;
        }

        $this->assertCoversExactly($conversation, array_keys($roster), $recipientIds);

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        /** @var list<Device> $notified */
        $notified = [];

        foreach ($wraps as $entry) {
            $device = $roster[$entry['recipientDeviceId']];
            $this->entityManager->persist(
                new ConversationKey($conversation, $epoch, $device, $entry['wrap'], $attestation, $now),
            );
            $notified[] = $device;
        }

        $this->entityManager->flush();

        return $notified;
    }

    /**
     * C2 — le bundle doit couvrir EXACTEMENT le roster actif, ni plus ni moins.
     *
     * Un bundle partiel serait une exclusion silencieuse : l'appareil oublié n'obtiendrait
     * aucun wrap de la nouvelle époque et deviendrait sourd sans qu'aucune erreur ne soit
     * levée nulle part. Un bundle excédentaire, lui, désigne un roster périmé côté client.
     *
     * @param list<string> $rosterIds
     * @param list<string> $recipientIds
     */
    private function assertCoversExactly(Conversation $conversation, array $rosterIds, array $recipientIds): void
    {
        $unknown = array_values(array_diff($recipientIds, $rosterIds));

        if ([] !== $unknown) {
            throw new ConflictException(
                \sprintf('L\'appareil « %s » ne fait pas partie du roster.', $unknown[0]),
                $this->rosterPresenter->present($conversation),
            );
        }

        $missing = array_values(array_diff($rosterIds, $recipientIds));

        if ([] !== $missing) {
            throw new ConflictException(
                \sprintf('Le bundle ne couvre pas tout le roster : l\'appareil « %s » n\'a reçu aucun wrap.', $missing[0]),
                $this->rosterPresenter->present($conversation),
            );
        }
    }

    /**
     * Revendication atomique de l'époque : même patron que
     * InvitationRepository::consumeAtomically(). Le `current_epoch < :epoch` interdit toute
     * régression d'époque, le `member_set_version = :version` interdit de rotationner sur une
     * composition qui a bougé entre la lecture du roster et le dépôt.
     */
    private function claimEpoch(Conversation $conversation, int $epoch, int $memberSetVersion): int
    {
        $connection = $this->entityManager->getConnection();
        $uuidType = Type::getType('uuid');

        return $connection->executeStatement(
            'UPDATE conversation SET current_epoch = :epoch WHERE id = :id AND current_epoch < :epoch AND member_set_version = :version',
            [
                'epoch' => $epoch,
                'version' => $memberSetVersion,
                // L'identifiant est stocké sous sa forme binaire de 16 octets : lier la
                // représentation textuelle ne sélectionnerait silencieusement aucune ligne.
                'id' => $uuidType->convertToDatabaseValue($conversation->getId(), $connection->getDatabasePlatform()),
            ],
            [
                'epoch' => ParameterType::INTEGER,
                'version' => ParameterType::INTEGER,
                'id' => $uuidType->getBindingType(),
            ],
        );
    }
}
