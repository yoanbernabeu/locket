<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Conflit de concurrence (époque déjà prise, roster périmé…) → 409.
 * Transporte une charge utile permettant au client de se resynchroniser sans requête
 * supplémentaire, comme l'exige API.md §2 (« répondre 409 avec le roster à jour »).
 */
class ConflictException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $message, private readonly array $payload = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
