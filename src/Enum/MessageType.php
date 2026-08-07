<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Octet 1 de l'enveloppe LK1M — la seule métadonnée en clair d'un message.
 *
 * Le champ reste stocké en `smallint` brut : un client plus récent peut introduire un type
 * inconnu du serveur, qui doit alors le router sans le comprendre. Cette énumération ne sert
 * qu'aux décisions serveur (ne pas notifier une réaction).
 */
enum MessageType: int
{
    case Text = 0x01;
    case Media = 0x02;
    case Reaction = 0x03;
    case Control = 0x04;

    /**
     * Une réaction ou un message de contrôle ne doit jamais réveiller le téléphone :
     * un « ❤️ » n'est pas une notification.
     */
    public static function shouldNotify(int $rawType): bool
    {
        return match (self::tryFrom($rawType)) {
            self::Reaction, self::Control => false,
            default => true,
        };
    }
}
