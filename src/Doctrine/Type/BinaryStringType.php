<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * Stockage BLOB côté base, chaîne PHP côté application.
 *
 * POURQUOI un type maison plutôt que `blob` ou `binary` : les deux types natifs de DBAL
 * renvoient une *ressource* de flux en lecture. Manipuler des flux pour des valeurs de 32 à
 * 130 octets est un piège permanent (un flux déjà consommé renvoie une chaîne vide, et un
 * `===` sur une ressource ne compare rien d'utile). Ici, l'enveloppe d'un message ne dépasse
 * jamais 64 Kio et une clé publique fait 32 octets : tout tient en mémoire sans risque.
 *
 * Le type de liaison LARGE_OBJECT est indispensable : sans lui, PDO SQLite lie la valeur en
 * TEXT et tronque au premier octet nul — ce qui arrive systématiquement avec des clés ou du
 * texte chiffré.
 */
final class BinaryStringType extends Type
{
    public const string NAME = 'binary_string';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getBlobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (\is_resource($value)) {
            $contents = stream_get_contents($value);

            return false === $contents ? '' : $contents;
        }

        return (string) $value;
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::LARGE_OBJECT;
    }
}
