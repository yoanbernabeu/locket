<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Device;
use App\Entity\User;
use App\Repository\DeviceRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Appareil rattaché à la session courante.
 *
 * La session ne conserve que l'identifiant : l'appareil est rechargé et revalidé à chaque
 * requête contre l'utilisateur authentifié. Une session volée et rejouée après révocation de
 * l'appareil n'ouvre donc rien.
 */
final class DeviceContext
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly DeviceRepository $devices,
        private readonly Security $security,
    ) {
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function getDevice(): ?Device
    {
        $user = $this->getUser();
        $request = $this->requestStack->getCurrentRequest();

        if (null === $user || null === $request || !$request->hasSession()) {
            return null;
        }

        $deviceId = $request->getSession()->get(PinAuthenticator::SESSION_DEVICE_KEY);

        if (!\is_string($deviceId)) {
            return null;
        }

        $device = $this->devices->findOneByDeviceId($deviceId);

        return null !== $device && $device->getUser()->getId()->equals($user->getId()) ? $device : null;
    }

    public function requireUser(): User
    {
        return $this->getUser() ?? throw new AccessDeniedException('Authentification requise.');
    }

    /**
     * Appareil de la session, quel que soit son statut. Utile aux actions d'enrôlement, qui
     * doivent connaître l'appareil courant précisément pour lui refuser certains gestes.
     *
     * @throws AccessDeniedException si la session ne désigne aucun appareil
     */
    public function requireDevice(): Device
    {
        return $this->getDevice() ?? throw new AccessDeniedException('Aucun appareil n\'est associé à cette session.');
    }

    /**
     * @throws AccessDeniedException si aucun appareil actif n'est associé à la session
     */
    public function requireActiveDevice(): Device
    {
        $device = $this->getDevice();

        if (null === $device || !$device->isActive()) {
            throw new AccessDeniedException('Cet appareil n\'est pas autorisé : approbation requise.');
        }

        return $device;
    }
}
