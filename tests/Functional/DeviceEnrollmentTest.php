<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\User;
use App\Enum\DeviceStatus;
use App\Repository\DeviceRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

final class DeviceEnrollmentTest extends ApiTestCase
{
    private User $admin;
    private Device $adminDevice;
    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->admin = $installed['user'];
        $this->adminDevice = $installed['device'];
        $this->group = $installed['conversation'];
        $this->fixtures->rotateKeys($this->group);
    }

    #[TestDox('un appareil pending ne peut pas lire les messages')]
    public function testPendingDeviceCannotReadMessages(): void
    {
        $pendingId = $this->registerSecondDevice();

        // Nouvelle session portée par l'appareil en attente d'appairage.
        $this->logout();
        $this->login('papa', '123456', $pendingId);
        self::assertSame('pending', $this->json()['status']);

        $this->request('GET', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $this->request('GET', '/api/conversations');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $this->request('POST', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages', [
            'envelope' => Base64Url::encode(EnvelopeFactory::envelope($this->group->getId(), 1)),
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('un appareil pending garde accès aux seuls endpoints d\'enrôlement')]
    public function testPendingDeviceKeepsEnrollmentEndpoints(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        $this->request('GET', '/api/me');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('pending', $this->json()['device']['status']);

        $this->request('GET', '/api/devices');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertCount(2, $this->json()['devices']);
    }

    #[TestDox('C4 : un appareil pending peut charger la coquille de l\'application')]
    public function testPendingDeviceCanLoadTheApplicationShell(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        // Le refus par défaut s'appliquait à TOUTES les routes, gabarits Twig compris :
        // l'appareil en attente recevait un 403 sur la coquille et ne pouvait donc jamais
        // afficher son propre code d'appairage. L'enrôlement était structurellement bloqué.
        foreach (['/', '/reglages', '/c/'.$this->group->getId()->toRfc4122(), '/j'] as $path) {
            $this->client->request('GET', $path);
            self::assertSame(Response::HTTP_OK, $this->httpStatus(), 'Chemin refusé : '.$path);
        }
    }

    #[TestDox('C4 : la sonde /health répond, avec ou sans session')]
    public function testHealthProbeIsNeverGuarded(): void
    {
        $this->client->request('GET', '/health');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $pendingId = $this->registerSecondDevice();
        $this->logout();
        $this->login('papa', '123456', $pendingId);

        $this->client->request('GET', '/health');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());
    }

    #[TestDox('C4 : un appareil pending reste refusé sur l\'écran d\'administration')]
    public function testPendingDeviceStillCannotReachAdminScreen(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        // L'ouverture de la coquille ne doit pas ouvrir le seul gabarit qui, lui, expose de
        // vraies données : la liste des membres et de leurs appareils.
        $this->client->request('GET', '/admin');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('C5 : un appareil pending ne peut pas révoquer un appareil actif')]
    public function testPendingDeviceCannotRevokeAnActiveDevice(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        // Depuis une simple session pseudo + PIN, sans qu'aucun humain n'ait comparé le code
        // d'appairage, cet appareil pouvait révoquer le seul appareil actif du compte — donc
        // le seul capable de l'approuver. Le compte était brique en une requête.
        $this->request('DELETE', '/api/devices/'.$this->adminDevice->getDeviceId());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);
        self::assertSame(DeviceStatus::Active, $devices->findOneByDeviceId($this->adminDevice->getDeviceId())?->getStatus());
    }

    #[TestDox('C5 : un appareil pending peut en revanche se révoquer lui-même')]
    public function testPendingDeviceMayRevokeItself(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        $this->request('DELETE', '/api/devices/'.$pendingId);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);
        self::assertSame(DeviceStatus::Revoked, $devices->findOneByDeviceId($pendingId)?->getStatus());
    }

    #[TestDox('C6 : le certificat d\'approbation et de quoi le vérifier sont exposés')]
    public function testApprovalCertificateIsExposedToClients(): void
    {
        $pendingId = $this->registerSecondDevice();
        $certificate = random_bytes(64);

        $this->request('POST', '/api/devices/'.$pendingId.'/approve', [
            'approvalCert' => Base64Url::encode($certificate),
        ]);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        // Sur la fiche d'appareil…
        $this->request('GET', '/api/devices');
        $approved = $this->deviceEntry($this->json()['devices'], $pendingId);

        self::assertSame(Base64Url::encode($certificate), $approved['approvalCert']);
        self::assertSame($this->adminDevice->getDeviceId(), $approved['approvedByDeviceId']);
        self::assertSame(Base64Url::encode($this->adminDevice->getSkPub()), $approved['approverSkPub']);

        // … et sur le roster, qui est la vue dont le client se sert pour écarter un appareil
        // dont l'attestation n'est pas vérifiable avant de lui adresser un wrap.
        $this->request('GET', '/api/conversations/'.$this->group->getId()->toRfc4122().'/roster');
        $entry = $this->deviceEntry($this->json()['devices'], $pendingId);

        self::assertSame(Base64Url::encode($certificate), $entry['approvalCert']);
        self::assertSame($this->adminDevice->getDeviceId(), $entry['approvedByDeviceId']);
        self::assertSame(Base64Url::encode($this->adminDevice->getSkPub()), $entry['approverSkPub']);
    }

    #[TestDox('C6 : un appareil d\'amorçage porte des champs d\'attestation nuls, jamais absents')]
    public function testBootstrapDeviceExposesNullCertificateFields(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('GET', '/api/devices');

        $entry = $this->deviceEntry($this->json()['devices'], $this->adminDevice->getDeviceId());

        // Le premier appareil d'un compte n'a aucun approbateur (confiance à la première
        // utilisation) : les clés doivent tout de même être présentes, pour que le client
        // distingue « non vérifiable » de « champ oublié par le serveur ».
        self::assertArrayHasKey('approvalCert', $entry);
        self::assertNull($entry['approvalCert']);
        self::assertNull($entry['approvedByDeviceId']);
        self::assertNull($entry['approverSkPub']);
    }

    #[TestDox('un appareil actif approuve un appareil en attente et déclenche une rotation')]
    public function testApprovalActivatesDeviceAndBumpsMemberSetVersion(): void
    {
        $pendingId = $this->registerSecondDevice();
        $versionBefore = $this->reloadGroup()->getMemberSetVersion();

        $this->logout();
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/devices/'.$pendingId.'/approve', [
            'approvalCert' => Base64Url::encode(random_bytes(64)),
        ]);

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertSame('active', $this->json()['status']);
        self::assertGreaterThan($versionBefore, $this->reloadGroup()->getMemberSetVersion());

        $devices = self::getContainer()->get(DeviceRepository::class);
        \assert($devices instanceof DeviceRepository);
        self::assertSame(DeviceStatus::Active, $devices->findOneByDeviceId($pendingId)?->getStatus());
    }

    #[TestDox('un appareil en attente ne peut pas s\'approuver lui-même')]
    public function testPendingDeviceCannotSelfApprove(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $pendingId);

        $this->request('POST', '/api/devices/'.$pendingId.'/approve', ['approvalCert' => null]);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('un appareil ne peut pas approuver celui d\'un autre membre')]
    public function testApprovalIsScopedToOwnAccount(): void
    {
        $mamie = $this->fixtures->addMember('mamie', '654321');

        $this->logout();
        $this->login('mamie', '654321', $mamie['device']->getDeviceId());

        $foreignId = EnvelopeFactory::deviceId();
        $this->request('POST', '/api/devices', [
            'deviceId' => $foreignId,
            'ikPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'skPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'label' => 'Tablette de mamie',
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $this->logout();
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/devices/'.$foreignId.'/approve', ['approvalCert' => null]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('la révocation d\'un appareil coupe son accès dès la requête suivante')]
    public function testRevocationEndsAccess(): void
    {
        $pendingId = $this->registerSecondDevice();

        $this->logout();
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/devices/'.$pendingId.'/approve', ['approvalCert' => null]);
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $this->logout();
        $this->login('papa', '123456', $pendingId);
        $this->request('GET', '/api/conversations');
        self::assertSame(Response::HTTP_OK, $this->httpStatus());

        $this->request('DELETE', '/api/devices/'.$pendingId);
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());

        // La session existe encore, mais l'appareil qu'elle désigne est révoqué.
        $this->request('GET', '/api/conversations');
        self::assertSame(Response::HTTP_FORBIDDEN, $this->httpStatus());
    }

    #[TestDox('un identifiant d\'appareil déjà pris est refusé')]
    public function testDuplicateDeviceIdIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/devices', [
            'deviceId' => $this->adminDevice->getDeviceId(),
            'ikPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'skPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'label' => 'Doublon',
        ]);

        self::assertSame(Response::HTTP_CONFLICT, $this->httpStatus());
    }

    #[TestDox('une clé publique de taille invalide est refusée')]
    public function testMalformedPublicKeyIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/devices', [
            'deviceId' => EnvelopeFactory::deviceId(),
            'ikPub' => Base64Url::encode(random_bytes(16)),
            'skPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'label' => 'Bancal',
        ]);

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus());
    }

    private function registerSecondDevice(): string
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $deviceId = EnvelopeFactory::deviceId();
        $this->request('POST', '/api/devices', [
            'deviceId' => $deviceId,
            'ikPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'skPub' => Base64Url::encode(EnvelopeFactory::publicKey()),
            'label' => 'Tablette',
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        self::assertSame('pending', $this->json()['status']);

        return $deviceId;
    }

    private function reloadGroup(): Conversation
    {
        return $this->fixtures->globalGroup();
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, mixed>
     */
    private function deviceEntry(array $entries, string $deviceId): array
    {
        foreach ($entries as $entry) {
            if ($deviceId === ($entry['deviceId'] ?? null)) {
                return $entry;
            }
        }

        self::fail('Appareil « '.$deviceId.' » absent de la réponse.');
    }
}
