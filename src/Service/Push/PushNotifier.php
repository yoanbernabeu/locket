<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\PushSubscription;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Envoi Web Push.
 *
 * Le client PSR-18 est injecté (symfony/http-client + nyholm/psr7) plutôt que découvert :
 * la découverte automatique dépend des paquets installés, ce qui rend le comportement
 * imprévisible en production comme en test.
 */
final class PushNotifier
{
    public function __construct(
        private readonly VapidKeyProvider $vapid,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PushSubscription> $subscriptions
     *
     * @return int nombre d'envois réussis
     */
    public function send(array $subscriptions, PushPayload $payload): int
    {
        if ([] === $subscriptions) {
            return 0;
        }

        $auth = $this->vapid->getAuth();

        if (null === $auth) {
            $this->logger->warning('Notification push ignorée : aucune clé VAPID configurée.');

            return 0;
        }

        $webPush = new WebPush(
            auth: $auth,
            client: $this->httpClient,
            requestFactory: $this->requestFactory,
            streamFactory: $this->streamFactory,
            logger: $this->logger,
        );

        /** @var array<string, PushSubscription> $byEndpoint */
        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->getEndpoint()] = $subscription;
            $webPush->queueNotification($this->toWebPushSubscription($subscription), $payload->json);
        }

        $delivered = 0;

        foreach ($webPush->flush() as $report) {
            $subscription = $byEndpoint[$report->getEndpoint()] ?? null;

            if ($report->isSuccess()) {
                ++$delivered;
                continue;
            }

            if (null === $subscription) {
                continue;
            }

            // 404 / 410 : l'abonnement est définitivement mort côté service de push. Le
            // conserver ferait échouer tous les envois suivants et, sur iOS, accélérerait la
            // révocation de l'abonnement suivant.
            if ($report->isSubscriptionExpired()) {
                $this->entityManager->remove($subscription);
                continue;
            }

            $subscription->registerFailure();
            $this->logger->notice('Échec d\'envoi push.', ['endpoint' => $report->getEndpoint(), 'reason' => $report->getReason()]);
        }

        $this->entityManager->flush();

        return $delivered;
    }

    private function toWebPushSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->getEndpoint(),
            'publicKey' => $subscription->getP256dh(),
            'authToken' => $subscription->getAuth(),
            'contentEncoding' => 'aes128gcm',
        ]);
    }
}
