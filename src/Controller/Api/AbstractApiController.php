<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\InvalidPayloadException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Décodage et validation d'entrée mutualisés.
 *
 * Les contrôleurs de Locket ne portent AUCUNE logique métier : ils décodent, délèguent à un
 * service du domaine, puis sérialisent. Tout ce qui ressemble à une règle appartient ailleurs.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $content = $request->getContent();

        if ('' === $content) {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidPayloadException('Corps de requête JSON invalide.');
        }

        if (!\is_array($decoded)) {
            throw new InvalidPayloadException('Un objet JSON est attendu.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value) || '' === $value) {
            throw new InvalidPayloadException(\sprintf('Le champ « %s » est requis.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function optionalString(array $payload, string $key, ?string $default = null): ?string
    {
        $value = $payload[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function requiredInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (!\is_int($value)) {
            throw new InvalidPayloadException(\sprintf('Le champ « %s » doit être un entier.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    protected function stringList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (!\is_array($value)) {
            throw new InvalidPayloadException(\sprintf('Le champ « %s » doit être une liste.', $key));
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw new InvalidPayloadException(\sprintf('Le champ « %s » ne doit contenir que des chaînes.', $key));
            }
        }

        return array_values($value);
    }

    protected function parseUuid(string $value, string $field): Uuid
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidPayloadException(\sprintf('Le champ « %s » n\'est pas un identifiant valide.', $field));
        }

        return Uuid::fromString($value);
    }
}
