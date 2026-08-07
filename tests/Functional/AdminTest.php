<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Device;
use App\Entity\User;
use App\Enum\DeviceStatus;
use App\Repository\DeviceRepository;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

final class AdminTest extends ApiTestCase
{
    private User $admin;
    private Device $adminDevice;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->admin = $installed['user'];
        $this->adminDevice = $installed['device'];
    }

    #[TestDox('un admin ne peut pas désactiver un autre admin')]
    public function testAdminCannotDisableAnotherAdmin(): void
    {
        $otherAdmin = $this->fixtures->addMember('maman', '654321', isAdmin: true);

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$otherAdmin['user']->getId()->toRfc4122().'/disable');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $reloaded = $this->users()->findOneByPseudo('maman');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isDisabled());
    }

    #[TestDox('un admin ne peut pas se désactiver lui-même')]
    public function testAdminCannotDisableSelf(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$this->admin->getId()->toRfc4122().'/disable');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('un admin désactive un membre ordinaire, ce qui révoque ses appareils')]
    public function testAdminDisablesRegularMember(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$member['user']->getId()->toRfc4122().'/disable');

        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        $reloaded = $this->users()->findOneByPseudo('mamie');
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isDisabled());

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);
        self::assertSame(DeviceStatus::Revoked, $devices->findOneByDeviceId($member['device']->getDeviceId())?->getStatus());
    }

    #[TestDox('un membre ordinaire n\'accède à aucun endpoint d\'administration')]
    public function testRegularMemberIsDeniedAdminEndpoints(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');
        $victim = $this->fixtures->addMember('tonton', '111222');

        $this->login('mamie', '654321', $member['device']->getDeviceId());

        $this->request('GET', '/api/admin/overview');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $this->request('GET', '/api/invites');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $this->request('POST', '/api/admin/members/'.$victim['user']->getId()->toRfc4122().'/disable');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('C19 : un admin promeut un membre ordinaire au rang d\'administrateur')]
    public function testAdminPromotesRegularMember(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$member['user']->getId()->toRfc4122().'/promote');

        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        // Sans cet endpoint, une instance n'avait qu'un seul administrateur pour toujours :
        // `setAdmin()` n'était appelé qu'à l'installation, et AdminVoter interdit par ailleurs
        // de retirer un admin. Un téléphone perdu laissait la famille sans gestionnaire.
        $reloaded = $this->users()->findOneByPseudo('mamie');
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isAdmin());
    }

    #[TestDox('C19 : le nouvel administrateur exerce réellement ses prérogatives')]
    public function testPromotedMemberGainsAdminEndpoints(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$member['user']->getId()->toRfc4122().'/promote');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        $this->logout();
        $this->login('mamie', '654321', $member['device']->getDeviceId());

        $this->request('GET', '/api/admin/overview');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
    }

    #[TestDox('C19 : promouvoir un membre déjà administrateur renvoie 409')]
    public function testPromotingAnExistingAdminConflicts(): void
    {
        $otherAdmin = $this->fixtures->addMember('maman', '654321', isAdmin: true);

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$otherAdmin['user']->getId()->toRfc4122().'/promote');

        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());
    }

    #[TestDox('C19 : un membre ordinaire ne peut promouvoir personne')]
    public function testRegularMemberCannotPromote(): void
    {
        $member = $this->fixtures->addMember('mamie', '654321');
        $target = $this->fixtures->addMember('tonton', '111222');

        $this->login('mamie', '654321', $member['device']->getDeviceId());
        $this->request('POST', '/api/admin/members/'.$target['user']->getId()->toRfc4122().'/promote');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $reloaded = $this->users()->findOneByPseudo('tonton');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isAdmin());
    }

    #[TestDox('la vue d\'administration liste les membres, leurs appareils et les invitations')]
    public function testOverviewListsMembersAndInvites(): void
    {
        $this->fixtures->addMember('mamie', '654321');

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/invites', [
            'inviteIdHash' => bin2hex(random_bytes(16)),
            'pseudo' => 'tonton',
            'displayName' => 'Tonton',
            'purpose' => 'new_member',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $this->request('GET', '/api/admin/overview');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        $body = $this->json();
        self::assertCount(2, $body['members']);
        self::assertCount(1, $body['invites']);
        self::assertSame('tonton', $body['invites'][0]['pseudo']);
    }
}
