<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\ConversationType;
use App\Enum\DeviceStatus;
use App\Exception\InvalidPayloadException;
use App\Repository\ConversationMemberRepository;
use App\Repository\ConversationRepository;
use App\Repository\DeviceRepository;
use App\Service\Membership\MembershipService;
use App\Tests\Support\DomainTestCase;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Uid\Uuid;

final class MembershipServiceTest extends DomainTestCase
{
    private MembershipService $membership;
    private User $admin;
    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        $membership = self::getContainer()->get(MembershipService::class);
        \assert($membership instanceof MembershipService);
        $this->membership = $membership;

        $installed = $this->fixtures->install();
        $this->admin = $installed['user'];
        $this->group = $installed['conversation'];
    }

    #[TestDox('la création d\'une conversation directe est idempotente')]
    public function testDirectConversationIsIdempotent(): void
    {
        $mamie = $this->fixtures->addMember('mamie')['user'];

        $first = $this->membership->getOrCreateDirectConversation($this->admin, $mamie);
        $second = $this->membership->getOrCreateDirectConversation($mamie, $this->admin);

        self::assertTrue($first->getId()->equals($second->getId()));
        self::assertSame(ConversationType::Direct, $first->getType());

        $conversations = self::getContainer()->get(ConversationRepository::class);
        \assert($conversations instanceof ConversationRepository);
        self::assertCount(1, $conversations->findBy(['type' => ConversationType::Direct]));
    }

    #[TestDox('C13 : une conversation directe déjà créée entre-temps est récupérée, jamais dupliquée')]
    public function testConcurrentDirectConversationIsRecoveredNotDuplicated(): void
    {
        $mamie = $this->fixtures->addMember('mamie')['user'];
        $directKey = Conversation::directKeyFor($this->admin->getId(), $mamie->getId());

        // Un appel concurrent crée la conversation depuis une AUTRE connexion, entre le moment
        // où notre appel constate son absence et celui où il l'insérerait.
        $this->createDirectConversationOnAnotherConnection($directKey);

        $conversation = $this->membership->getOrCreateDirectConversation($this->admin, $mamie);

        // Le code d'origine partait à l'insertion, heurtait la contrainte d'unicité, et
        // laissait l'EntityManager CLOS : la relecture censée rattraper la course levait à son
        // tour, et la requête finissait en 500. On vérifie donc aussi que l'unité de travail
        // reste utilisable ensuite.
        self::assertSame($directKey, $conversation->getDirectKey());
        self::assertTrue($this->entityManager->isOpen());

        $conversations = self::getContainer()->get(ConversationRepository::class);
        \assert($conversations instanceof ConversationRepository);
        self::assertCount(1, $conversations->findBy(['type' => ConversationType::Direct]));

        // L'unité de travail accepte encore une écriture : `leave()` va jusqu'au flush.
        $this->membership->leave($this->fixtures->globalGroup(), $mamie);
    }

    #[TestDox('la clé de conversation directe ne dépend pas de l\'ordre des membres')]
    public function testDirectKeyIsOrderIndependent(): void
    {
        $mamie = $this->fixtures->addMember('mamie')['user'];

        self::assertSame(
            Conversation::directKeyFor($this->admin->getId(), $mamie->getId()),
            Conversation::directKeyFor($mamie->getId(), $this->admin->getId()),
        );
    }

    #[TestDox('une conversation directe avec soi-même est refusée')]
    public function testSelfDirectConversationIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->membership->getOrCreateDirectConversation($this->admin, $this->admin);
    }

    /**
     * Insère la conversation directe depuis une connexion distincte, comme le ferait un autre
     * processus PHP servant une requête concurrente.
     */
    private function createDirectConversationOnAnotherConnection(string $directKey): void
    {
        $other = DriverManager::getConnection($this->entityManager->getConnection()->getParams());

        $other->executeStatement(
            'INSERT INTO conversation (id, type, title, current_epoch, member_set_version, direct_key, created_at) VALUES (:id, :type, NULL, 0, 1, :directKey, :now)',
            [
                'id' => Uuid::v7()->toBinary(),
                'type' => ConversationType::Direct->value,
                'directKey' => $directKey,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        $other->close();
    }

    #[TestDox('chaque changement de composition incrémente memberSetVersion')]
    public function testEveryCompositionChangeBumpsVersion(): void
    {
        $initial = $this->group->getMemberSetVersion();

        $mamie = $this->fixtures->addMember('mamie')['user'];
        $this->entityManager->refresh($this->group);
        $afterJoin = $this->group->getMemberSetVersion();

        $this->membership->leave($this->group, $mamie);
        $this->entityManager->refresh($this->group);
        $afterLeave = $this->group->getMemberSetVersion();

        self::assertGreaterThan($initial, $afterJoin);
        self::assertGreaterThan($afterJoin, $afterLeave);
    }

    #[TestDox('rejoindre une conversation dont on est déjà membre actif ne change rien')]
    public function testJoiningTwiceIsANoop(): void
    {
        $mamie = $this->fixtures->addMember('mamie')['user'];
        $this->entityManager->refresh($this->group);
        $version = $this->group->getMemberSetVersion();

        $this->membership->join($this->group, $mamie);
        $this->entityManager->refresh($this->group);

        self::assertSame($version, $this->group->getMemberSetVersion());
    }

    #[TestDox('la désactivation d\'un membre révoque ses appareils et le sort de ses conversations')]
    public function testDisableMemberCascades(): void
    {
        $member = $this->fixtures->addMember('mamie');
        $this->entityManager->refresh($this->group);
        $versionBefore = $this->group->getMemberSetVersion();

        $this->membership->disableMember($member['user']);
        $this->entityManager->refresh($this->group);

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);

        $members = self::getContainer()->get(ConversationMemberRepository::class);
        \assert($members instanceof ConversationMemberRepository);

        self::assertTrue($member['user']->isDisabled());
        self::assertSame(DeviceStatus::Revoked, $devices->findOneByDeviceId($member['device']->getDeviceId())?->getStatus());
        self::assertFalse($members->isActiveMember($this->group, $member['user']));
        self::assertGreaterThan($versionBefore, $this->group->getMemberSetVersion());

        // Un membre retiré ne figure plus au roster, donc ne recevra plus jamais de wrap.
        $rosterIds = array_map(
            static fn (\App\Entity\Device $device): string => $device->getDeviceId(),
            $devices->findActiveDevicesForConversation($this->group),
        );

        self::assertNotContains($member['device']->getDeviceId(), $rosterIds);
    }

    #[TestDox('l\'approbation d\'un appareil incrémente memberSetVersion sur toutes les conversations')]
    public function testDeviceApprovalRefreshesAllConversations(): void
    {
        $mamie = $this->fixtures->addMember('mamie')['user'];
        $direct = $this->membership->getOrCreateDirectConversation($this->admin, $mamie);

        $this->entityManager->refresh($this->group);
        $groupVersion = $this->group->getMemberSetVersion();
        $directVersion = $direct->getMemberSetVersion();

        $deviceService = self::getContainer()->get(\App\Service\Device\DeviceService::class);
        \assert($deviceService instanceof \App\Service\Device\DeviceService);

        $pending = $deviceService->register(
            $mamie,
            \App\Tests\Support\EnvelopeFactory::deviceId(),
            \App\Tests\Support\EnvelopeFactory::publicKey(),
            \App\Tests\Support\EnvelopeFactory::publicKey(),
            'Tablette',
        );

        $approver = self::getContainer()->get(DeviceRepository::class);
        \assert($approver instanceof DeviceRepository);
        $active = $approver->findActiveByUser($mamie)[0];

        $deviceService->approve($pending, $active, random_bytes(64));

        $this->entityManager->refresh($this->group);
        $this->entityManager->refresh($direct);

        self::assertGreaterThan($groupVersion, $this->group->getMemberSetVersion());
        self::assertGreaterThan($directVersion, $direct->getMemberSetVersion());
        self::assertSame(DeviceStatus::Active, $pending->getStatus());
    }
}
