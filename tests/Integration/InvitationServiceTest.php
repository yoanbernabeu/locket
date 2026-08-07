<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Dto\DeviceEnrollment;
use App\Entity\User;
use App\Enum\DeviceStatus;
use App\Enum\InvitationPurpose;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Repository\DeviceRepository;
use App\Repository\InvitationRepository;
use App\Repository\UserRepository;
use App\Service\Invitation\InvitationService;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\EnvelopeFactory;
use PHPUnit\Framework\Attributes\TestDox;

final class InvitationServiceTest extends DomainTestCase
{
    private InvitationService $invitations;
    private InvitationRepository $repository;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $invitations = self::getContainer()->get(InvitationService::class);
        \assert($invitations instanceof InvitationService);
        $this->invitations = $invitations;

        $repository = self::getContainer()->get(InvitationRepository::class);
        \assert($repository instanceof InvitationRepository);
        $this->repository = $repository;

        $this->admin = $this->fixtures->install()['user'];
    }

    #[TestDox('une invitation ne peut être consommée qu\'une seule fois')]
    public function testInvitationIsConsumedOnlyOnce(): void
    {
        $hash = bin2hex(random_bytes(16));
        $this->invitations->create($this->admin, $hash, 'mamie', 'Mamie', InvitationPurpose::NewMember, null);

        $first = $this->invitations->claim($hash, '112233', $this->enrollment());
        self::assertSame('mamie', $first['user']->getPseudo());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('déjà été utilisée');

        $this->invitations->claim($hash, '112233', $this->enrollment());
    }

    #[TestDox('une invitation ne peut être consommée qu\'une seule fois, même en concurrence')]
    public function testAtomicConsumptionResistsConcurrency(): void
    {
        $hash = bin2hex(random_bytes(16));
        $invitation = $this->invitations->create($this->admin, $hash, 'tonton', 'Tonton', InvitationPurpose::NewMember, null);

        $now = new \DateTimeImmutable();

        // Deux consommations entrelacées sur la MÊME ligne : c'est le nombre de lignes
        // affectées par l'UPDATE conditionnel qui départage, pas une lecture préalable.
        $firstWins = $this->repository->consumeAtomically($invitation, $now);
        $secondWins = $this->repository->consumeAtomically($invitation, $now);

        self::assertTrue($firstWins);
        self::assertFalse($secondWins);
    }

    #[TestDox('une invitation révoquée ne peut plus être consommée')]
    public function testRevokedInvitationCannotBeClaimed(): void
    {
        $hash = bin2hex(random_bytes(16));
        $invitation = $this->invitations->create($this->admin, $hash, 'cousin', 'Cousin', InvitationPurpose::NewMember, null);
        $this->invitations->revoke($invitation);

        $this->expectException(ConflictException::class);

        $this->invitations->claim($hash, '445566', $this->enrollment());
    }

    #[TestDox('la consommation d\'une invitation ajoute le nouveau membre au groupe global')]
    public function testClaimJoinsGlobalGroup(): void
    {
        $hash = bin2hex(random_bytes(16));
        $this->invitations->create($this->admin, $hash, 'mamie', 'Mamie', InvitationPurpose::NewMember, null);

        $before = $this->fixtures->globalGroup()->getMemberSetVersion();
        $result = $this->invitations->claim($hash, '112233', $this->enrollment());

        $group = $this->fixtures->globalGroup();

        self::assertGreaterThan($before, $group->getMemberSetVersion());
        self::assertSame(DeviceStatus::Active, $result['device']->getStatus());
    }

    #[TestDox('une récupération révoque tous les anciens appareils du membre')]
    public function testRecoveryRevokesPreviousDevices(): void
    {
        $member = $this->fixtures->addMember('mamie');
        $hash = bin2hex(random_bytes(16));

        $this->invitations->create(
            $this->admin,
            $hash,
            'mamie',
            'Mamie',
            InvitationPurpose::Recovery,
            $member['user']->getId(),
        );

        $result = $this->invitations->claim($hash, '778899', $this->enrollment());

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);

        $revoked = $devices->findOneByDeviceId($member['device']->getDeviceId());
        self::assertNotNull($revoked);
        self::assertSame(DeviceStatus::Revoked, $revoked->getStatus());
        self::assertSame(DeviceStatus::Active, $result['device']->getStatus());

        $users = self::getContainer()->get(UserRepository::class);
        \assert($users instanceof UserRepository);
        self::assertSame($member['user']->getId()->toRfc4122(), $result['user']->getId()->toRfc4122());
        self::assertNotNull($users->findOneByPseudo('mamie'));
    }

    #[TestDox('C7 : une invitation de récupération visant un administrateur est refusée')]
    public function testRecoveryTargetingAnAdminIsForbidden(): void
    {
        $otherAdmin = $this->fixtures->addMember('maman', '654321', isAdmin: true)['user'];

        // Une récupération réécrit le PIN de sa cible et enrôle un appareil sans code à
        // comparer : elle donne le contrôle total du compte. Un admin pouvait ainsi prendre
        // la place de n'importe quel autre, y compris un autre admin — alors que le même
        // administrateur n'a pas le droit de simplement le désactiver.
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('administrateur');

        $this->invitations->create(
            $this->admin,
            bin2hex(random_bytes(16)),
            'maman',
            'Maman',
            InvitationPurpose::Recovery,
            $otherAdmin->getId(),
        );
    }

    #[TestDox('C7 : un administrateur ne peut pas non plus se cibler lui-même')]
    public function testRecoveryTargetingSelfIsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->invitations->create(
            $this->admin,
            bin2hex(random_bytes(16)),
            'papa',
            'Papa',
            InvitationPurpose::Recovery,
            $this->admin->getId(),
        );
    }

    #[TestDox('C8 : une récupération incrémente memberSetVersion, ce qui force la rotation')]
    public function testRecoveryBumpsMemberSetVersion(): void
    {
        $member = $this->fixtures->addMember('mamie');
        $versionBefore = $this->fixtures->globalGroup()->getMemberSetVersion();

        $hash = bin2hex(random_bytes(16));
        $this->invitations->create(
            $this->admin,
            $hash,
            'mamie',
            'Mamie',
            InvitationPurpose::Recovery,
            $member['user']->getId(),
        );

        $this->invitations->claim($hash, '778899', $this->enrollment());
        $this->entityManager->clear();

        // Révoquer les appareils ne suffisait pas : sans incrément, aucun client ne déclenche
        // de rotation et le téléphone perdu conserve une clé d'époque courante bien vivante.
        self::assertGreaterThan($versionBefore, $this->fixtures->globalGroup()->getMemberSetVersion());
    }

    #[TestDox('un pseudo déjà pris est refusé à la création d\'invitation')]
    public function testDuplicatePseudoIsRejected(): void
    {
        $this->expectException(ConflictException::class);

        $this->invitations->create($this->admin, bin2hex(random_bytes(16)), 'papa', 'Papa', InvitationPurpose::NewMember, null);
    }

    private function enrollment(): DeviceEnrollment
    {
        return new DeviceEnrollment(
            EnvelopeFactory::deviceId(),
            EnvelopeFactory::publicKey(),
            EnvelopeFactory::publicKey(),
            'Nouveau téléphone',
        );
    }
}
