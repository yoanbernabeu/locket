<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use Symfony\Component\Uid\Uuid;

/**
 * Fabrique des noms de topics Mercure (API.md §3). Centralisée : un topic mal formé dans un
 * JWT et l'appareil n'entend plus rien, ou entend ce qui ne le regarde pas.
 */
final class MercureTopics
{
    public function conversation(Uuid $conversationId): string
    {
        return 'locket://conversation/'.$conversationId->toRfc4122();
    }

    public function device(string $deviceId): string
    {
        return 'locket://device/'.$deviceId;
    }
}
