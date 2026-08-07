<?php

declare(strict_types=1);

namespace App\Service\Message;

use Symfony\Component\Uid\Uuid;

/**
 * En-tête LK1M décodé — les 50 premiers octets, tout ce que le serveur peut lire d'un message.
 */
final readonly class EnvelopeHeader
{
    public function __construct(
        public int $version,
        public int $msgType,
        public Uuid $conversationId,
        public int $keyEpoch,
    ) {
    }
}
