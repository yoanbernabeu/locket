<?php

declare(strict_types=1);

namespace App\Util;

use App\Exception\InvalidPayloadException;

/**
 * Encodage base64url SANS remplissage (RFC 4648 §5), seul format binaire admis dans le JSON
 * de l'API. Centralisé ici pour que l'encodage et le décodage restent symétriques partout.
 */
final class Base64Url
{
    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @throws InvalidPayloadException si l'entrée n'est pas du base64url valide
     */
    public static function decode(string $encoded): string
    {
        // Le padding est interdit en entrée : l'accepter ouvrirait deux représentations
        // possibles pour un même binaire, et donc un contournement d'unicité applicative.
        if ('' !== $encoded && 1 !== preg_match('/\A[A-Za-z0-9\-_]+\z/', $encoded)) {
            throw new InvalidPayloadException('Valeur binaire attendue en base64url sans remplissage.');
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if (false === $decoded) {
            throw new InvalidPayloadException('Valeur binaire attendue en base64url sans remplissage.');
        }

        return $decoded;
    }

    /**
     * Décode en vérifiant une longueur exacte attendue (clés publiques, wraps…).
     *
     * @throws InvalidPayloadException
     */
    public static function decodeExactly(string $encoded, int $expectedBytes, string $field): string
    {
        $decoded = self::decode($encoded);

        if (\strlen($decoded) !== $expectedBytes) {
            throw new InvalidPayloadException(\sprintf('Le champ « %s » doit faire %d octets.', $field, $expectedBytes));
        }

        return $decoded;
    }
}
