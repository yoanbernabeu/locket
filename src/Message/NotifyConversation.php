<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Demande d'envoi des notifications push d'un message.
 *
 * On transporte des identifiants et l'enveloppe encodée, jamais des entités : le message peut
 * être sérialisé dans un transport et consommé par un autre processus. Les destinataires sont
 * résolus au moment du traitement, pour refléter l'état le plus récent de la composition.
 */
final readonly class NotifyConversation
{
    public function __construct(
        public string $conversationId,
        public string $senderDeviceId,
        public string $encodedEnvelope,
        public int $keyEpoch,
    ) {
    }
}
