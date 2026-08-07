<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * Parcours complet du premier lancement à l'arrivée d'un second membre.
 */
final class OnboardingTest extends ApiTestCase
{
    #[TestDox('l\'assistant d\'installation crée l\'instance, le premier admin et le groupe global')]
    public function testSetupCreatesInstanceAdminAndGlobalGroup(): void
    {
        // L'assistant est servi tant que l'instance n'est pas installée ; sa forme (JSON ou
        // gabarit Twig) relève de la couche de présentation, seul le code compte ici.
        $this->request('GET', '/setup');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $deviceId = EnvelopeFactory::deviceId();
        $this->request('POST', '/setup', [
            'instanceName' => 'Famille Durand',
            'pseudo' => 'papa',
            'displayName' => 'Papa',
            'email' => 'papa@example.test',
            'pin' => '123456',
            'device' => $this->devicePayload($deviceId),
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        $body = $this->json();
        self::assertSame('Famille Durand', $body['instance']['name']);
        self::assertTrue($body['user']['isAdmin']);
        self::assertSame('active', $body['device']['status']);

        // Une fois installée, la route d'installation disparaît.
        $this->request('GET', '/setup');
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());

        $this->request('POST', '/setup', ['pseudo' => 'intrus', 'pin' => '000000', 'device' => $this->devicePayload(EnvelopeFactory::deviceId())]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());

        $this->login('papa', '123456', $deviceId);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $this->request('GET', '/api/conversations');
        $conversations = $this->json();
        self::assertCount(1, $conversations);
        self::assertSame('group', $conversations[0]['type']);
    }

    #[TestDox('un PIN qui n\'est pas de six chiffres est refusé à l\'installation')]
    public function testSetupRejectsMalformedPin(): void
    {
        $this->request('POST', '/setup', [
            'pseudo' => 'papa',
            'pin' => '12',
            'device' => $this->devicePayload(EnvelopeFactory::deviceId()),
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    #[TestDox('un invité rejoint la famille et se retrouve dans le groupe global')]
    public function testInvitedMemberJoinsGlobalGroup(): void
    {
        $installed = $this->fixtures->install('papa', '123456');
        $this->login('papa', '123456', $installed['device']->getDeviceId());

        $inviteIdHash = bin2hex(random_bytes(16));
        $this->request('POST', '/api/invites', [
            'inviteIdHash' => $inviteIdHash,
            'pseudo' => 'mamie',
            'displayName' => 'Mamie',
            'purpose' => 'new_member',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $this->logout();

        $deviceId = EnvelopeFactory::deviceId();
        $this->request('POST', '/api/invites/claim', [
            'inviteIdHash' => $inviteIdHash,
            'pin' => '654321',
            'device' => $this->devicePayload($deviceId),
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        self::assertSame('mamie', $this->json()['user']['pseudo']);
        self::assertFalse($this->json()['user']['isAdmin']);

        // Le jeton est consommé : une seconde tentative échoue.
        $this->request('POST', '/api/invites/claim', [
            'inviteIdHash' => $inviteIdHash,
            'pin' => '654321',
            'device' => $this->devicePayload(EnvelopeFactory::deviceId()),
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());

        $this->login('mamie', '654321', $deviceId);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $this->request('GET', '/api/conversations');
        self::assertCount(1, $this->json());
        self::assertCount(2, $this->json()[0]['members']);
    }

    #[TestDox('une invitation révoquée ne permet plus d\'entrer')]
    public function testRevokedInviteIsRefused(): void
    {
        $installed = $this->fixtures->install('papa', '123456');
        $this->login('papa', '123456', $installed['device']->getDeviceId());

        $inviteIdHash = bin2hex(random_bytes(16));
        $this->request('POST', '/api/invites', [
            'inviteIdHash' => $inviteIdHash,
            'pseudo' => 'mamie',
            'displayName' => 'Mamie',
            'purpose' => 'new_member',
        ]);
        $inviteId = $this->json()['id'];

        $this->request('DELETE', '/api/invites/'.$inviteId);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        $this->logout();

        $this->request('POST', '/api/invites/claim', [
            'inviteIdHash' => $inviteIdHash,
            'pin' => '654321',
            'device' => $this->devicePayload(EnvelopeFactory::deviceId()),
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());
    }

    #[TestDox('une empreinte d\'invitation inconnue renvoie 404 sans rien révéler')]
    public function testUnknownInviteHashIsNotFound(): void
    {
        $this->fixtures->install('papa', '123456');

        $this->request('POST', '/api/invites/claim', [
            'inviteIdHash' => bin2hex(random_bytes(16)),
            'pin' => '654321',
            'device' => $this->devicePayload(EnvelopeFactory::deviceId()),
        ]);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->httpStatus());
    }

    /**
     * @return array{deviceId: string, ikPub: string, skPub: string, label: string}
     */
    private function devicePayload(string $deviceId): array
    {
        return [
            'deviceId' => $deviceId,
            'ikPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'skPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'label' => 'Téléphone',
        ];
    }
}
