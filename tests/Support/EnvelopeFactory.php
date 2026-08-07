<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ConversationKey;
use Symfony\Component\Uid\Uuid;

/**
 * Fabrique d'enveloppes LK1M et de wraps LK1W de test.
 *
 * Le contenu chiffré est du bruit : le serveur ne le déchiffre jamais, seule la forme de
 * l'en-tête compte pour les tests côté serveur.
 */
final class EnvelopeFactory
{
    public static function envelope(Uuid $conversationId, int $epoch = 1, int $msgType = 0x01, int $bodyBytes = 32, int $version = 0x01): string
    {
        $header = \chr($version)
            .\chr($msgType)
            .$conversationId->toBinary()
            .pack('N', $epoch)
            .random_bytes(16)
            .random_bytes(12);

        return $header.random_bytes($bodyBytes + 16);
    }

    public static function wrap(): string
    {
        return random_bytes(ConversationKey::WRAP_BYTES);
    }

    /**
     * Attestation de rotation factice.
     *
     * Le serveur ne la vérifie pas — il n'en a pas les moyens, et ce n'est pas
     * son rôle : ce sont les clients qui la vérifient entre eux. Il doit en
     * revanche la stocker et la restituer intacte.
     */
    public static function attestation(): string
    {
        return random_bytes(ConversationKey::ATTESTATION_BYTES);
    }

    public static function deviceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function publicKey(): string
    {
        return random_bytes(32);
    }
}
