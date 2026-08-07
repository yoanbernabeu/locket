<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Conversation;
use App\Entity\Device;
use App\Entity\Message;
use App\Util\Base64Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Diffusion temps réel.
 *
 * Toutes les mises à jour sont marquées PRIVÉES : sans cela, le hub les servirait à n'importe
 * quel abonné, y compris non authentifié. Les enveloppes restant chiffrées, la fuite serait
 * limitée aux métadonnées — ce qui est déjà inacceptable ici.
 *
 * Une panne du hub ne doit jamais faire échouer l'écriture d'un message : le client
 * rattrapera par synchronisation incrémentale. Les erreurs sont donc journalisées, pas
 * propagées.
 */
final class RealtimePublisher
{
    public function __construct(
        private readonly HubInterface $hub,
        private readonly MercureTopics $topics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publishMessage(Message $message): void
    {
        // `id` et `conversationId` ne sont pas décoratifs : le client déduplique sur `id` face
        // au rattrapage par synchronisation, et route sur `conversationId` puisqu'un même
        // flux Mercure porte toutes ses conversations. Sans eux, la livraison est rejetée.
        $this->publish($this->topics->conversation($message->getConversation()->getId()), [
            'type' => 'message',
            'id' => $message->getId()->toRfc4122(),
            'conversationId' => $message->getConversation()->getId()->toRfc4122(),
            'seq' => $message->getServerSeq(),
            'envelope' => Base64Url::encode($message->getEnvelope()),
            'createdAt' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'senderPseudo' => $message->getSenderUser()->getPseudo(),
        ]);
    }

    public function publishDevicePending(Device $target, Device $recipient): void
    {
        $this->publish($this->topics->device($recipient->getDeviceId()), [
            'type' => 'device.pending',
            'deviceId' => $target->getDeviceId(),
            'label' => $target->getLabel(),
            'ikPub' => Base64Url::encode($target->getIkPub()),
            'skPub' => Base64Url::encode($target->getSkPub()),
        ]);
    }

    public function publishKeyRotated(Device $recipient, Conversation $conversation, int $epoch): void
    {
        $this->publish($this->topics->device($recipient->getDeviceId()), [
            'type' => 'key.rotated',
            'conversationId' => $conversation->getId()->toRfc4122(),
            'epoch' => $epoch,
        ]);
    }

    public function publishRosterChanged(Device $recipient, Conversation $conversation): void
    {
        $this->publish($this->topics->device($recipient->getDeviceId()), [
            'type' => 'roster.changed',
            'conversationId' => $conversation->getId()->toRfc4122(),
            'memberSetVersion' => $conversation->getMemberSetVersion(),
        ]);
    }

    /**
     * @param list<Device> $recipients
     */
    public function publishRosterChangedToAll(array $recipients, Conversation $conversation): void
    {
        foreach ($recipients as $recipient) {
            $this->publishRosterChanged($recipient, $conversation);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function publish(string $topic, array $data): void
    {
        try {
            $this->hub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR), private: true));
        } catch (\Throwable $exception) {
            $this->logger->error('Publication Mercure impossible.', ['topic' => $topic, 'exception' => $exception]);
        }
    }
}
