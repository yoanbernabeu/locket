<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotifyConversation;
use App\Repository\ConversationRepository;
use App\Repository\DeviceRepository;
use App\Repository\PushSubscriptionRepository;
use App\Service\Push\PushNotifier;
use App\Service\Push\PushPayloadBuilder;
use App\Util\Base64Url;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class NotifyConversationHandler
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly DeviceRepository $devices,
        private readonly PushSubscriptionRepository $subscriptions,
        private readonly PushPayloadBuilder $payloadBuilder,
        private readonly PushNotifier $notifier,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(NotifyConversation $notification): void
    {
        $conversation = $this->conversations->findOneById(Uuid::fromString($notification->conversationId));

        if (null === $conversation) {
            return;
        }

        // L'appareil émetteur est exclu : il vient d'écrire le message, le notifier lui
        // ferait afficher sa propre phrase en notification système.
        $recipients = array_values(array_filter(
            $this->devices->findActiveDevicesForConversation($conversation),
            static fn ($device): bool => $device->getDeviceId() !== $notification->senderDeviceId,
        ));

        $subscriptions = $this->subscriptions->findByDevices($recipients);

        if ([] === $subscriptions) {
            return;
        }

        $payload = $this->payloadBuilder->build(
            $notification->conversationId,
            $notification->keyEpoch,
            Base64Url::decode($notification->encodedEnvelope),
            $this->buildNavigateUrl($notification->conversationId),
        );

        $this->notifier->send($subscriptions, $payload);
    }

    /**
     * Construit une URL absolue depuis le contexte de routage : en consommation asynchrone
     * il n'y a aucune requête HTTP dont on pourrait déduire l'hôte (DEFAULT_URI le fournit).
     */
    private function buildNavigateUrl(string $conversationId): string
    {
        $context = $this->urlGenerator->getContext();
        $port = match (true) {
            'http' === $context->getScheme() && 80 !== $context->getHttpPort() => ':'.$context->getHttpPort(),
            'https' === $context->getScheme() && 443 !== $context->getHttpsPort() => ':'.$context->getHttpsPort(),
            default => '',
        };

        return $context->getScheme().'://'.$context->getHost().$port.rtrim($context->getBaseUrl(), '/').'/c/'.$conversationId;
    }
}
