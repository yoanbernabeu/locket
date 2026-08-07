<?php

declare(strict_types=1);

namespace App\Service\Push;

use App\Entity\Device;
use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Util\Base64Url;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Cycle de vie de l'abonnement Web Push d'un appareil.
 */
final class PushSubscriptionService
{
    /** Point P-256 non compressé (0x04 ‖ X ‖ Y), tel que produit par PushManager. */
    private const int P256DH_BYTES = 65;

    /** Secret d'authentification RFC 8291. */
    private const int AUTH_BYTES = 16;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushEndpointValidator $endpoints,
        private readonly ClockInterface $clock,
    ) {
    }

    public function subscribe(Device $device, string $endpoint, string $p256dh, string $auth): PushSubscription
    {
        $endpoint = $this->endpoints->validate($endpoint);

        // Les deux clés sont décodées ici uniquement pour en contrôler la taille : elles sont
        // stockées telles quelles, minishlink/web-push attendant du base64url.
        Base64Url::decodeExactly($p256dh, self::P256DH_BYTES, 'p256dh');
        Base64Url::decodeExactly($auth, self::AUTH_BYTES, 'auth');

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        // Un abonnement par appareil : le service de push réémet un nouvel endpoint à chaque
        // renouvellement, et conserver les anciens ne ferait qu'accumuler des envois voués à
        // l'échec — que certaines plateformes comptent contre nous.
        $subscription = $this->subscriptions->findOneByDevice($device);

        if (null === $subscription) {
            $subscription = new PushSubscription($device, $endpoint, $p256dh, $auth, $now);
            $this->entityManager->persist($subscription);
        } else {
            $subscription->replaceEndpoint($endpoint, $p256dh, $auth, $now);
        }

        $this->entityManager->flush();

        return $subscription;
    }

    public function unsubscribe(Device $device): void
    {
        $subscription = $this->subscriptions->findOneByDevice($device);

        if (null === $subscription) {
            return;
        }

        $this->entityManager->remove($subscription);
        $this->entityManager->flush();
    }
}
