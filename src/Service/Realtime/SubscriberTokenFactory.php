<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Device;
use App\Repository\ConversationRepository;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

/**
 * Fabrique le JWT d'abonnement d'un appareil.
 *
 * RÈGLE DE SÉCURITÉ : la liste des topics est calculée côté serveur et n'énumère QUE ceux
 * auxquels l'appareil courant a droit. Aucun joker, jamais : un `*` dans le champ `subscribe`
 * donnerait à n'importe quel appareil la totalité du trafic temps réel de l'instance.
 * Le jeton n'accorde aucun droit de publication — seul le serveur publie.
 */
final class SubscriberTokenFactory
{
    public function __construct(
        private readonly TokenFactoryInterface $tokenFactory,
        private readonly ConversationRepository $conversations,
        private readonly MercureTopics $topics,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedTopics(Device $device): array
    {
        $topics = [$this->topics->device($device->getDeviceId())];

        foreach ($this->conversations->findActiveForUser($device->getUser()) as $conversation) {
            $topics[] = $this->topics->conversation($conversation->getId());
        }

        return $topics;
    }

    public function create(Device $device): string
    {
        return $this->tokenFactory->create($this->allowedTopics($device), []);
    }
}
