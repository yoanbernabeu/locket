<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Hub en mémoire, substitué au hub réel en environnement de test.
 *
 * Sans lui, chaque publication tenterait un appel HTTP vers un hub absent : les tests
 * resteraient verts (RealtimePublisher avale les erreurs) mais n'établiraient rien sur le
 * temps réel, et la sortie serait noyée de journaux d'erreur.
 */
final class RecordingHub implements HubInterface
{
    /** @var list<Update> */
    private array $updates = [];

    public function __construct(private readonly string $publicUrl = '/.well-known/mercure')
    {
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'urn:uuid:'.bin2hex(random_bytes(8));
    }

    /**
     * @return list<Update>
     */
    public function getUpdates(): array
    {
        return $this->updates;
    }

    /**
     * @return list<string>
     */
    public function getPublishedTopics(): array
    {
        $topics = [];

        foreach ($this->updates as $update) {
            foreach ($update->getTopics() as $topic) {
                $topics[] = $topic;
            }
        }

        return $topics;
    }

    public function reset(): void
    {
        $this->updates = [];
    }
}
