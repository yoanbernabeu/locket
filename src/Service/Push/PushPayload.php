<?php

declare(strict_types=1);

namespace App\Service\Push;

final readonly class PushPayload
{
    public function __construct(
        public string $json,
        public bool $includesEnvelope,
    ) {
    }

    public function size(): int
    {
        return \strlen($this->json);
    }
}
