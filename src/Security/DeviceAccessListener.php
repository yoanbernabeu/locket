<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\DeviceStatus;
use App\Security\Attribute\AllowDeviceStatus;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Applique la règle « session liée à un appareil » avant l'exécution du contrôleur.
 *
 * Le refus par défaut est délibéré : un endpoint ajouté demain sera fermé aux appareils non
 * approuvés sans que personne n'ait à y penser. C'est l'inverse d'une liste blanche oubliée.
 *
 * PORTÉE : uniquement les routes qui servent des données. Les routes Twig de la coquille
 * (`/`, `/reglages`, `/c/{uuid}`, `/j`) ne rendent qu'un gabarit vide — le serveur ne détient
 * aucune clé — et doivent rester ouvertes à un appareil `pending`, faute de quoi celui-ci ne
 * peut même pas afficher son code d'appairage : l'enrôlement devient impossible.
 * `/admin` reste couvert : c'est le seul gabarit qui, lui, expose des données réelles.
 * La sonde `/health` n'est jamais soumise à cette règle.
 */
#[AsEventListener(event: ControllerArgumentsEvent::class, priority: -8)]
final class DeviceAccessListener
{
    private const string HEALTH_PATH = '/health';

    /** @var list<string> */
    private const array GUARDED_PREFIXES = ['/api/', '/admin'];

    public function __construct(private readonly DeviceContext $deviceContext)
    {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest() || null === $this->deviceContext->getUser()) {
            return;
        }

        if (!$this->isGuarded($event->getRequest()->getPathInfo())) {
            return;
        }

        $allowed = $this->allowedStatuses($event);
        $device = $this->deviceContext->getDevice();

        if (null === $device) {
            // Une session sans appareil (connexion sans deviceId) ne peut qu'enrôler.
            if (null === $allowed) {
                throw new AccessDeniedException('Aucun appareil n\'est associé à cette session.');
            }

            return;
        }

        $statuses = $allowed ?? [DeviceStatus::Active];

        if (!\in_array($device->getStatus(), $statuses, true)) {
            throw new AccessDeniedException('Cet appareil n\'est pas autorisé : approbation requise.');
        }
    }

    private function isGuarded(string $path): bool
    {
        if (self::HEALTH_PATH === $path) {
            return false;
        }

        foreach (self::GUARDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<DeviceStatus>|null null lorsque l'action n'accepte que des appareils actifs
     */
    private function allowedStatuses(ControllerArgumentsEvent $event): ?array
    {
        /** @var list<AllowDeviceStatus> $attributes */
        $attributes = $event->getAttributes(AllowDeviceStatus::class);

        if ([] === $attributes) {
            return null;
        }

        return [...$attributes[0]->statuses, DeviceStatus::Active];
    }
}
