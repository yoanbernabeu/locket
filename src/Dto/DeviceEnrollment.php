<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Device;
use App\Exception\InvalidPayloadException;
use App\Util\Base64Url;

/**
 * Données d'enrôlement d'un appareil, décodées et validées une seule fois à la frontière HTTP.
 */
final readonly class DeviceEnrollment
{
    public function __construct(
        public string $deviceId,
        public string $ikPub,
        public string $skPub,
        public string $label,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws InvalidPayloadException
     */
    public static function fromPayload(mixed $payload): self
    {
        if (!\is_array($payload)) {
            throw new InvalidPayloadException('Les données d\'appareil sont absentes.');
        }

        foreach (['deviceId', 'ikPub', 'skPub'] as $field) {
            if (!\is_string($payload[$field] ?? null) || '' === $payload[$field]) {
                throw new InvalidPayloadException(\sprintf('Le champ « %s » est requis.', $field));
            }
        }

        return new self(
            deviceId: $payload['deviceId'],
            ikPub: Base64Url::decodeExactly($payload['ikPub'], Device::PUBLIC_KEY_BYTES, 'ikPub'),
            skPub: Base64Url::decodeExactly($payload['skPub'], Device::PUBLIC_KEY_BYTES, 'skPub'),
            label: \is_string($payload['label'] ?? null) ? $payload['label'] : 'Appareil',
        );
    }
}
