<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Exception\ConflictException;
use App\Exception\InvalidPayloadException;
use App\Repository\ConversationKeyRepository;
use App\Repository\DeviceRepository;
use App\Service\Key\ConversationKeyService;
use App\Service\Realtime\RecordingHub;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\EnvelopeFactory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\TestDox;

final class ConversationKeyServiceTest extends DomainTestCase
{
    private ConversationKeyService $keys;
    private Conversation $group;
    private Device $adminDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $keys = self::getContainer()->get(ConversationKeyService::class);
        \assert($keys instanceof ConversationKeyService);
        $this->keys = $keys;

        $installed = $this->fixtures->install();
        $this->group = $installed['conversation'];
        $this->adminDevice = $installed['device'];
    }

    #[TestDox('un premier bundle de rotation fixe l\'époque courante')]
    public function testFirstBundleSetsCurrentEpoch(): void
    {
        $version = $this->group->getMemberSetVersion();
        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());

        self::assertSame($version, $this->group->getCurrentEpoch());
        self::assertNotNull($this->keys->findWrap($this->group, $version, $this->adminDevice));
    }

    #[TestDox('C1 : une époque différente du memberSetVersion est refusée')]
    public function testEpochMustEqualMemberSetVersion(): void
    {
        $version = $this->group->getMemberSetVersion();

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('exactement égale au numéro de composition');

        $this->keys->deposit($this->group, $version + 1, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());
    }

    #[TestDox('C1 : une époque arbitrairement lointaine ne peut plus bloquer la rotation')]
    public function testAbsurdlyHighEpochIsRejectedAndLeavesRotationPossible(): void
    {
        $version = $this->group->getMemberSetVersion();

        try {
            // L'attaque d'origine : un seul POST à epoch = 1 000 000 fixait current_epoch si
            // haut qu'aucune rotation ultérieure — toujours égale au memberSetVersion — ne
            // pouvait plus le dépasser. La conversation était figée pour toujours.
            $this->keys->deposit($this->group, 1000000, 1000000, $this->wrapsForRoster(), EnvelopeFactory::attestation());
            self::fail('Un refus était attendu.');
        } catch (InvalidPayloadException|ConflictException) {
            $this->entityManager->clear();
        }

        $group = $this->fixtures->globalGroup();
        self::assertSame(0, $group->getCurrentEpoch(), 'Aucune époque n\'a été revendiquée.');

        // La rotation légitime reste possible.
        $this->keys->deposit($group, $version, $version, $this->wrapsForRoster($group), EnvelopeFactory::attestation());
        self::assertSame($version, $group->getCurrentEpoch());
    }

    #[TestDox('C1 : une époque au-delà de 0xFFFFFFFF est refusée')]
    public function testEpochIsBoundedToThirtyTwoBits(): void
    {
        $overflow = ConversationKeyService::MAX_EPOCH + 1;

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('ne peut pas dépasser');

        $this->keys->deposit($this->group, $overflow, $overflow, $this->wrapsForRoster(), EnvelopeFactory::attestation());
    }

    #[TestDox('C2 : un bundle qui oublie un appareil du roster est refusé')]
    public function testPartialBundleIsRejected(): void
    {
        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $version = $this->group->getMemberSetVersion();

        // Un seul wrap alors que le roster en compte deux : sans ce contrôle, mamie serait
        // silencieusement exclue de la nouvelle époque, sans la moindre erreur nulle part.
        $partial = [['recipientDeviceId' => $this->adminDevice->getDeviceId(), 'wrap' => EnvelopeFactory::wrap()]];

        try {
            $this->keys->deposit($this->group, $version, $version, $partial, EnvelopeFactory::attestation());
            self::fail('Un conflit était attendu.');
        } catch (ConflictException $exception) {
            self::assertStringContainsString('ne couvre pas tout le roster', $exception->getMessage());
            $this->entityManager->clear();
        }

        self::assertSame(0, $this->fixtures->globalGroup()->getCurrentEpoch());
    }

    #[TestDox('C2 : un bundle qui répète deux fois le même appareil est refusé')]
    public function testDuplicateRecipientIsRejected(): void
    {
        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $version = $this->group->getMemberSetVersion();

        // Deux wraps pour le même appareil : le compte est bon, la couverture ne l'est pas.
        $duplicated = [
            ['recipientDeviceId' => $this->adminDevice->getDeviceId(), 'wrap' => EnvelopeFactory::wrap()],
            ['recipientDeviceId' => $this->adminDevice->getDeviceId(), 'wrap' => EnvelopeFactory::wrap()],
        ];

        $this->expectException(InvalidPayloadException::class);
        $this->expectExceptionMessage('deux wraps pour le même appareil');

        $this->keys->deposit($this->group, $version, $version, $duplicated, EnvelopeFactory::attestation());
    }

    #[TestDox('un second bundle visant la même époque reçoit un 409')]
    public function testSecondBundleForSameEpochConflicts(): void
    {
        $version = $this->group->getMemberSetVersion();

        // Les deux clients ont lu le même roster et visent donc la même époque.
        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('époque supérieure ou égale');

        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());
    }

    #[TestDox('C11 : une rotation concurrente committée entre-temps produit un 409, jamais un 500')]
    public function testConcurrentWriterOnAnotherConnectionIsArbitratedByAffectedRows(): void
    {
        $version = $this->group->getMemberSetVersion();
        $wraps = $this->wrapsForRoster();

        // Vraie concurrence : une SECONDE connexion revendique l'époque pendant que
        // l'EntityManager de ce processus croit encore la conversation à l'époque 0. Aucune
        // relecture préalable en PHP ne peut voir ce changement — seule la clause WHERE de
        // l'UPDATE atomique l'arbitre. C'est exactement ce que l'ancien test, purement
        // séquentiel, ne pouvait pas démontrer.
        $this->claimEpochFromAnotherConnection($version);

        self::assertSame(0, $this->group->getCurrentEpoch(), 'L\'entité locale est bien restée périmée.');

        try {
            $this->keys->deposit($this->group, $version, $version, $wraps, EnvelopeFactory::attestation());
            self::fail('Un conflit était attendu.');
        } catch (ConflictException $exception) {
            self::assertStringContainsString('époque supérieure ou égale', $exception->getMessage());
        }

        $this->entityManager->clear();

        $repository = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($repository instanceof ConversationKeyRepository);

        // Le perdant n'a laissé aucune clé derrière lui.
        self::assertFalse($repository->epochExists($this->fixtures->globalGroup(), $version));
    }

    #[TestDox('C12 : une rotation annulée ne publie aucun événement « key.rotated »')]
    public function testRolledBackRotationPublishesNothing(): void
    {
        $hub = self::getContainer()->get(RecordingHub::class);
        \assert($hub instanceof RecordingHub);

        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $version = $this->group->getMemberSetVersion();

        $wraps = $this->wrapsForRoster();
        $wraps[] = ['recipientDeviceId' => EnvelopeFactory::deviceId(), 'wrap' => EnvelopeFactory::wrap()];

        $hub->reset();

        try {
            $this->keys->deposit($this->group, $version, $version, $wraps, EnvelopeFactory::attestation());
            self::fail('Un conflit était attendu.');
        } catch (ConflictException) {
            $this->entityManager->clear();
        }

        // Les publications avaient lieu DANS la transaction : un rollback laissait les
        // appareils informés d'une époque qui n'a jamais existé, et ils allaient chercher un
        // wrap introuvable.
        self::assertSame([], $this->rotationEvents($hub));
    }

    #[TestDox('C12 : une rotation réussie publie « key.rotated » à chaque appareil du roster')]
    public function testSuccessfulRotationPublishesAfterCommit(): void
    {
        $hub = self::getContainer()->get(RecordingHub::class);
        \assert($hub instanceof RecordingHub);

        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $version = $this->group->getMemberSetVersion();

        $hub->reset();
        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());

        $events = $this->rotationEvents($hub);

        self::assertCount(2, $events);

        foreach ($events as $event) {
            self::assertSame($version, $event['epoch']);
            self::assertSame($this->group->getId()->toRfc4122(), $event['conversationId']);
        }
    }

    #[TestDox('le conflit d\'époque renvoie le roster à jour pour resynchroniser le client')]
    public function testConflictCarriesRoster(): void
    {
        $version = $this->group->getMemberSetVersion();
        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());

        try {
            $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), EnvelopeFactory::attestation());
            self::fail('Un conflit était attendu.');
        } catch (ConflictException $exception) {
            $payload = $exception->getPayload();

            self::assertArrayHasKey('memberSetVersion', $payload);
            self::assertArrayHasKey('devices', $payload);
            self::assertSame($version, $payload['currentEpoch']);
            self::assertCount(1, $payload['devices']);
        }
    }

    #[TestDox('un bundle rejeté ne laisse aucune clé derrière lui')]
    public function testRejectedBundleIsFullyRolledBack(): void
    {
        $version = $this->group->getMemberSetVersion();
        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);

        $wraps = $this->wrapsForRoster();
        // Un appareil inconnu du roster invalide tout le bundle : rien ne doit être écrit.
        $wraps[] = ['recipientDeviceId' => EnvelopeFactory::deviceId(), 'wrap' => EnvelopeFactory::wrap()];

        $repository = self::getContainer()->get(ConversationKeyRepository::class);
        \assert($repository instanceof ConversationKeyRepository);

        try {
            $this->keys->deposit($this->group, $this->group->getMemberSetVersion(), $this->group->getMemberSetVersion(), $wraps, EnvelopeFactory::attestation());
            self::fail('Un conflit était attendu.');
        } catch (ConflictException) {
            $this->entityManager->clear();
        }

        self::assertFalse($repository->epochExists($this->fixtures->globalGroup(), $version + 1));
        self::assertSame(0, $this->fixtures->globalGroup()->getCurrentEpoch());
    }

    #[TestDox('un roster périmé fait échouer la rotation en 409')]
    public function testStaleMemberSetVersionConflicts(): void
    {
        // Le client a lu le roster en version V, puis la composition a changé : sa rotation,
        // pourtant cohérente en elle-même (epoch = memberSetVersion = V), ne correspond plus
        // à la composition en base et doit être refusée.
        $staleVersion = $this->group->getMemberSetVersion();
        $staleWraps = $this->wrapsForRoster();

        $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('composition');

        $this->keys->deposit($this->group, $staleVersion, $staleVersion, $staleWraps, EnvelopeFactory::attestation());
    }

    #[TestDox('un wrap dont la taille n\'est pas celle du format LK1W est refusé')]
    public function testWrapLengthIsEnforced(): void
    {
        $version = $this->group->getMemberSetVersion();

        $this->expectException(InvalidPayloadException::class);

        $this->keys->deposit($this->group, $version, $version, [
            ['recipientDeviceId' => $this->adminDevice->getDeviceId(), 'wrap' => random_bytes(64)],
        ], EnvelopeFactory::attestation());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rotationEvents(RecordingHub $hub): array
    {
        $events = [];

        foreach ($hub->getUpdates() as $update) {
            $decoded = json_decode($update->getData(), true, 8, \JSON_THROW_ON_ERROR);

            if (\is_array($decoded) && 'key.rotated' === ($decoded['type'] ?? null)) {
                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Revendique l'époque depuis une connexion distincte, comme le ferait un autre processus
     * PHP servant une requête concurrente.
     */
    private function claimEpochFromAnotherConnection(int $epoch): void
    {
        $params = $this->entityManager->getConnection()->getParams();
        $other = DriverManager::getConnection($params);

        $affected = $other->executeStatement(
            'UPDATE conversation SET current_epoch = :epoch WHERE id = :id AND current_epoch < :epoch',
            [
                'epoch' => $epoch,
                'id' => $this->group->getId()->toBinary(),
            ],
        );

        $other->close();

        self::assertSame(1, $affected, 'La connexion concurrente doit avoir gagné.');
    }

    /**
     * @return list<array{recipientDeviceId: string, wrap: string}>
     */
    private function wrapsForRoster(?Conversation $conversation = null): array
    {
        $conversation ??= $this->group;

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);

        $wraps = [];

        foreach ($devices->findActiveDevicesForConversation($conversation) as $device) {
            $wraps[] = ['recipientDeviceId' => $device->getDeviceId(), 'wrap' => EnvelopeFactory::wrap()];
        }

        return $wraps;
    }

    #[TestDox("l'attestation de rotation est conservée et restituée telle quelle")]
    public function testAttestationIsStoredAndReturnedVerbatim(): void
    {
        $version = $this->group->getMemberSetVersion();
        $attestation = EnvelopeFactory::attestation();

        $this->keys->deposit($this->group, $version, $version, $this->wrapsForRoster(), $attestation);

        // Le serveur ne peut pas la vérifier — il n'a aucune clé privée — mais il
        // doit la rendre intacte : sans elle, le client n'a aucun moyen de
        // distinguer une clé légitime d'une clé fabriquée par ce même serveur.
        $entrees = $this->keys->findEpochWraps($this->group, $version);
        self::assertNotEmpty($entrees, 'Aucune enveloppe restituée pour cette époque.');

        foreach ($entrees as $entry) {
            self::assertSame($attestation, $entry->getAttestation());
        }
    }

    #[TestDox("toutes les enveloppes d'une époque sont restituées ensemble")]
    public function testEpochWrapsAreReturnedAsAWhole(): void
    {
        $version = $this->group->getMemberSetVersion();
        $wraps = $this->wrapsForRoster();
        $this->keys->deposit($this->group, $version, $version, $wraps, EnvelopeFactory::attestation());

        // L'attestation porte sur l'empreinte COLLECTIVE des enveloppes : le
        // client ne peut pas la vérifier s'il n'en reçoit qu'une.
        self::assertCount(\count($wraps), $this->keys->findEpochWraps($this->group, $version));
    }
}
