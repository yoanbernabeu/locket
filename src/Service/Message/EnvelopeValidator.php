<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\Message;
use App\Exception\InvalidPayloadException;
use Symfony\Component\Uid\Uuid;

/**
 * Contrôle de bonne formation d'une enveloppe LK1M — SANS aucun déchiffrement.
 *
 * Le contrôle du `convId` est une garantie de sécurité, pas une commodité : sans lui, un
 * client pourrait déposer dans la conversation A une enveloppe dont l'AAD désigne la
 * conversation B. Les membres de A ne pourraient pas l'ouvrir, mais le serveur aurait
 * accepté un message inexploitable et l'aurait diffusé. On refuse à l'entrée.
 */
final class EnvelopeValidator
{
    public const int VERSION = 0x01;

    private const int OFFSET_CONV_ID = 2;
    private const int OFFSET_KEY_EPOCH = 18;

    public function validate(string $envelope, Uuid $expectedConversationId): EnvelopeHeader
    {
        $length = \strlen($envelope);

        if ($length < Message::HEADER_BYTES) {
            throw new InvalidPayloadException(\sprintf('Enveloppe trop courte : %d octets pour un minimum de %d.', $length, Message::HEADER_BYTES));
        }

        if ($length > Message::MAX_ENVELOPE_BYTES) {
            throw new InvalidPayloadException(\sprintf('Enveloppe trop volumineuse : %d octets pour un maximum de %d.', $length, Message::MAX_ENVELOPE_BYTES));
        }

        $version = \ord($envelope[0]);

        if (self::VERSION !== $version) {
            throw new InvalidPayloadException(\sprintf('Version d\'enveloppe non prise en charge : 0x%02x.', $version));
        }

        // Comparaison sur les octets bruts : aucune interprétation du UUID n'est nécessaire,
        // et l'on évite de faire dépendre un contrôle de sécurité d'un analyseur syntaxique.
        $rawConvId = substr($envelope, self::OFFSET_CONV_ID, 16);

        if (!hash_equals($expectedConversationId->toBinary(), $rawConvId)) {
            throw new InvalidPayloadException('L\'identifiant de conversation de l\'enveloppe ne correspond pas à celui de l\'URL.');
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', substr($envelope, self::OFFSET_KEY_EPOCH, 4));

        return new EnvelopeHeader(
            version: $version,
            msgType: \ord($envelope[1]),
            conversationId: $expectedConversationId,
            keyEpoch: $unpacked[1],
        );
    }
}
