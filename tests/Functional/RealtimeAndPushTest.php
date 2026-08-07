<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Service\Realtime\RecordingHub;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

final class RealtimeAndPushTest extends ApiTestCase
{
    private Device $adminDevice;
    private Conversation $group;
    private int $epoch;

    protected function setUp(): void
    {
        parent::setUp();

        $installed = $this->fixtures->install('papa', '123456');
        $this->adminDevice = $installed['device'];
        $this->group = $installed['conversation'];
        $this->fixtures->addMember('mamie', '654321');
        $this->entityManager->refresh($this->group);
        $this->epoch = $this->fixtures->rotateKeys($this->group);
    }

    #[TestDox('le JWT d\'abonnement ne liste que les topics de l\'appareil, sans joker')]
    public function testSubscriberTokenListsOnlyOwnTopics(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('GET', '/api/realtime/token');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        $body = $this->json();

        self::assertContains('locket://device/'.$this->adminDevice->getDeviceId(), $body['topics']);
        self::assertContains('locket://conversation/'.$this->group->getId()->toRfc4122(), $body['topics']);
        self::assertNotContains('*', $body['topics']);

        $claims = $this->decodeJwtPayload($body['token']);

        self::assertSame($body['topics'], $claims['mercure']['subscribe']);
        // Aucun droit de publication : seul le serveur publie.
        self::assertSame([], $claims['mercure']['publish'] ?? []);
    }

    #[TestDox('un appareil ne reçoit pas de topic pour une conversation qu\'il a quittée')]
    public function testTokenExcludesLeftConversations(): void
    {
        $membership = self::getContainer()->get(\App\Service\Membership\MembershipService::class);
        \assert($membership instanceof \App\Service\Membership\MembershipService);

        $mamie = $this->users()->findOneByPseudo('mamie');
        self::assertNotNull($mamie);

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $membership->leave($this->fixtures->globalGroup(), $this->users()->findOneByPseudo('papa') ?? throw new \RuntimeException('absent'));

        $this->request('GET', '/api/realtime/token');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertNotContains('locket://conversation/'.$this->group->getId()->toRfc4122(), $this->json()['topics']);
    }

    #[TestDox('un message publié est diffusé sur le topic de la conversation')]
    public function testMessageIsPublishedOnConversationTopic(): void
    {
        $hub = self::getContainer()->get(RecordingHub::class);
        \assert($hub instanceof RecordingHub);
        $hub->reset();

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages', [
            'envelope' => Base64Url::encode(EnvelopeFactory::envelope($this->group->getId(), $this->epoch)),
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        self::assertContains('locket://conversation/'.$this->group->getId()->toRfc4122(), $hub->getPublishedTopics());

        foreach ($hub->getUpdates() as $update) {
            self::assertTrue($update->isPrivate(), 'Toute mise à jour Mercure doit être privée.');
        }
    }

    #[TestDox('C9 : la mise à jour temps réel d\'un message porte son id et sa conversation')]
    public function testPublishedMessageCarriesIdAndConversationId(): void
    {
        $hub = self::getContainer()->get(RecordingHub::class);
        \assert($hub instanceof RecordingHub);
        $hub->reset();

        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('POST', '/api/conversations/'.$this->group->getId()->toRfc4122().'/messages', [
            'envelope' => Base64Url::encode(EnvelopeFactory::envelope($this->group->getId(), $this->epoch)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
        $created = $this->json();

        $payload = $this->lastMessageUpdate($hub);

        // Sans ces deux champs, le client rejetait TOUTE livraison temps réel : il déduplique
        // sur `id` face au rattrapage par synchronisation, et route sur `conversationId`
        // puisqu'un même flux porte plusieurs conversations.
        self::assertSame($created['id'], $payload['id']);
        self::assertSame($this->group->getId()->toRfc4122(), $payload['conversationId']);
        self::assertSame($created['seq'], $payload['seq']);
        self::assertSame('papa', $payload['senderPseudo']);
        self::assertArrayHasKey('envelope', $payload);
    }

    #[TestDox('C10 : le JWT d\'abonnement Mercure expire au bout de cinq minutes')]
    public function testSubscriberTokenExpiresWithinFiveMinutes(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());
        $this->request('GET', '/api/realtime/token');

        $claims = $this->decodeJwtPayload($this->json()['token']);

        self::assertArrayHasKey('exp', $claims);
        // `exp` porte une fraction de seconde : la comparer à time(), tronqué à la seconde,
        // ferait apparaître une durée de vie légèrement supérieure à la vraie.
        $lifetime = $claims['exp'] - microtime(true);

        // Le hub ne sait rien du retrait d'un membre : la durée du jeton EST la fenêtre
        // pendant laquelle un membre exclu continue de recevoir le direct. Une heure était
        // une heure de trop.
        self::assertGreaterThan(0, $lifetime);
        self::assertLessThanOrEqual(300, $lifetime);
    }

    #[TestDox('C14 : un endpoint push visant le réseau interne est refusé')]
    public function testInternalPushEndpointIsRejected(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        // SSRF authentifiée : le serveur émet ensuite des requêtes répétées vers cette URL,
        // depuis l'intérieur du réseau.
        foreach ([
            'http://push.example.test/clair',
            'https://127.0.0.1/interne',
            'https://192.168.1.1/routeur',
            'https://169.254.169.254/latest/meta-data/',
            'https://localhost:8080/.well-known/mercure',
            'https://[::1]/boucle',
            'https://mon-nas/partage',
            'https://imprimante.local/etat',
        ] as $endpoint) {
            $this->request('POST', '/api/push/subscribe', [
                'endpoint' => $endpoint,
                'p256dh' => Base64Url::encode(random_bytes(65)),
                'auth' => Base64Url::encode(random_bytes(16)),
            ]);

            self::assertSame(Response::HTTP_BAD_REQUEST, $this->httpStatus(), 'Accepté à tort : '.$endpoint);
        }

        $subscriptions = self::getContainer()->get(\App\Repository\PushSubscriptionRepository::class);
        \assert($subscriptions instanceof \App\Repository\PushSubscriptionRepository);
        self::assertCount(0, $subscriptions->findAll());
    }

    #[TestDox('C14 : un endpoint push public reste accepté')]
    public function testPublicPushEndpointIsAccepted(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/push/subscribe', [
            'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/abcdef',
            'p256dh' => Base64Url::encode(random_bytes(65)),
            'auth' => Base64Url::encode(random_bytes(16)),
        ]);

        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());
    }

    #[TestDox('la clé publique VAPID est accessible sans authentification')]
    public function testVapidPublicKeyIsAnonymous(): void
    {
        $this->request('GET', '/api/push/vapid-public-key');

        self::assertSame(Response::HTTP_OK, $this->httpStatus());
        self::assertArrayHasKey('publicKey', $this->json());
    }

    #[TestDox('un appareil n\'a qu\'un seul abonnement push, remplacé à chaque renouvellement')]
    public function testSubscriptionIsReplacedNotDuplicated(): void
    {
        $this->login('papa', '123456', $this->adminDevice->getDeviceId());

        $this->request('POST', '/api/push/subscribe', [
            'endpoint' => 'https://push.example.test/premier',
            'p256dh' => Base64Url::encode(random_bytes(65)),
            'auth' => Base64Url::encode(random_bytes(16)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $this->request('POST', '/api/push/subscribe', [
            'endpoint' => 'https://push.example.test/second',
            'p256dh' => Base64Url::encode(random_bytes(65)),
            'auth' => Base64Url::encode(random_bytes(16)),
        ]);
        self::assertSame(Response::HTTP_CREATED, $this->httpStatus());

        $subscriptions = self::getContainer()->get(\App\Repository\PushSubscriptionRepository::class);
        \assert($subscriptions instanceof \App\Repository\PushSubscriptionRepository);

        self::assertCount(1, $subscriptions->findAll());
        self::assertSame('https://push.example.test/second', $subscriptions->findAll()[0]->getEndpoint());

        $this->request('DELETE', '/api/push/subscribe');
        self::assertSame(Response::HTTP_NO_CONTENT, $this->httpStatus());
        self::assertCount(0, $subscriptions->findAll());
    }

    /**
     * @return array<string, mixed>
     */
    private function lastMessageUpdate(RecordingHub $hub): array
    {
        foreach (array_reverse($hub->getUpdates()) as $update) {
            $decoded = json_decode($update->getData(), true, 8, \JSON_THROW_ON_ERROR);

            if (\is_array($decoded) && 'message' === ($decoded['type'] ?? null)) {
                return $decoded;
            }
        }

        self::fail('Aucune mise à jour de type « message » n\'a été publiée.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);

        $decoded = json_decode(Base64Url::decode($parts[1]), true, 8, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
