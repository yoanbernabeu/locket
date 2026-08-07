<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Device;
use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Service\Push\PushNotifier;
use App\Service\Push\PushPayloadBuilder;
use App\Service\Push\VapidKeyProvider;
use App\Tests\Support\DomainTestCase;
use App\Tests\Support\EnvelopeFactory;
use App\Util\Base64Url;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class PushNotifierTest extends DomainTestCase
{
    private Device $device;
    private PushSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = $this->fixtures->install()['device'];
        $this->subscription = new PushSubscription(
            $this->device,
            'https://push.example.test/abonnement',
            ...[...$this->generateSubscriptionKeys(), new \DateTimeImmutable()],
        );

        $this->entityManager->persist($this->subscription);
        $this->entityManager->flush();
    }

    #[TestDox('un abonnement révoqué par le service de push (410) est supprimé')]
    public function testExpiredSubscriptionIsDeleted(): void
    {
        $delivered = $this->notifierRespondingWith(410)->send([$this->subscription], $this->payload());

        self::assertSame(0, $delivered);
        self::assertNull($this->subscriptions()->findOneByDevice($this->device));
    }

    #[TestDox('un abonnement inconnu du service de push (404) est supprimé')]
    public function testUnknownSubscriptionIsDeleted(): void
    {
        $delivered = $this->notifierRespondingWith(404)->send([$this->subscription], $this->payload());

        self::assertSame(0, $delivered);
        self::assertNull($this->subscriptions()->findOneByDevice($this->device));
    }

    #[TestDox('une panne passagère du service de push conserve l\'abonnement')]
    public function testTransientFailureKeepsSubscription(): void
    {
        $delivered = $this->notifierRespondingWith(500)->send([$this->subscription], $this->payload());

        $reloaded = $this->subscriptions()->findOneByDevice($this->device);

        self::assertSame(0, $delivered);
        self::assertNotNull($reloaded);
        self::assertSame(1, $reloaded->getFailures());
    }

    #[TestDox('un envoi accepté est comptabilisé et laisse l\'abonnement en place')]
    public function testSuccessfulDelivery(): void
    {
        $delivered = $this->notifierRespondingWith(201)->send([$this->subscription], $this->payload());

        self::assertSame(1, $delivered);
        self::assertNotNull($this->subscriptions()->findOneByDevice($this->device));
    }

    #[TestDox('aucun envoi n\'est tenté sans clés VAPID')]
    public function testNoDeliveryWithoutVapidKeys(): void
    {
        // Ni environnement ni instance ne fournissent de clé : le service doit se taire
        // plutôt que de tenter un envoi voué à l'échec.
        $instances = $this->createStub(\App\Repository\InstanceRepository::class);
        $instances->method('get')->willReturn(null);

        $notifier = new PushNotifier(
            new VapidKeyProvider('', '', 'mailto:admin@example.test', $instances),
            $this->entityManager,
            new Psr18Client(new MockHttpClient()),
            $this->requestFactory(),
            $this->streamFactory(),
            new NullLogger(),
        );

        self::assertSame(0, $notifier->send([$this->subscription], $this->payload()));
        self::assertNotNull($this->subscriptions()->findOneByDevice($this->device));
    }

    private function notifierRespondingWith(int $statusCode): PushNotifier
    {
        $vapid = self::getContainer()->get(VapidKeyProvider::class);
        \assert($vapid instanceof VapidKeyProvider);
        self::assertTrue($vapid->isConfigured(), 'L\'installation doit avoir généré des clés VAPID.');

        $client = new Psr18Client(new MockHttpClient(new MockResponse('', ['http_code' => $statusCode])));

        return new PushNotifier(
            $vapid,
            $this->entityManager,
            $client,
            $this->requestFactory(),
            $this->streamFactory(),
            new NullLogger(),
        );
    }

    private function payload(): \App\Service\Push\PushPayload
    {
        return (new PushPayloadBuilder())->build(
            Uuid::v7()->toRfc4122(),
            1,
            EnvelopeFactory::envelope(Uuid::v7()),
            'https://locket.test/c/abc',
        );
    }

    private function requestFactory(): RequestFactoryInterface
    {
        $factory = self::getContainer()->get(RequestFactoryInterface::class);
        \assert($factory instanceof RequestFactoryInterface);

        return $factory;
    }

    private function streamFactory(): StreamFactoryInterface
    {
        $factory = self::getContainer()->get(StreamFactoryInterface::class);
        \assert($factory instanceof StreamFactoryInterface);

        return $factory;
    }

    private function subscriptions(): PushSubscriptionRepository
    {
        $repository = self::getContainer()->get(PushSubscriptionRepository::class);
        \assert($repository instanceof PushSubscriptionRepository);
        $this->entityManager->clear();

        return $repository;
    }

    /**
     * Le chiffrement RFC 8291 exige une vraie clé publique P-256 : des octets aléatoires
     * feraient échouer l'envoi avant même d'atteindre le service de push.
     *
     * @return array{string, string}
     */
    private function generateSubscriptionKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        \assert(false !== $key);

        $details = openssl_pkey_get_details($key);
        \assert(\is_array($details));

        $uncompressed = "\x04"
            .str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT);

        return [Base64Url::encode($uncompressed), Base64Url::encode(random_bytes(16))];
    }
}
