<?php

declare(strict_types=1);

namespace App\Service\Device;

use App\Entity\Device;
use App\Entity\User;
use App\Enum\DeviceStatus;
use App\Exception\ConflictException;
use App\Exception\ForbiddenException;
use App\Exception\InvalidPayloadException;
use App\Repository\DeviceRepository;
use App\Service\Membership\MembershipService;
use App\Service\Realtime\RealtimePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Cycle de vie des appareils (PROTOCOL.md §5).
 *
 * Le serveur ne vérifie JAMAIS le certificat d'approbation : il ne détient pas la clé
 * publique de signature dans un contexte de confiance suffisant, et surtout ce n'est pas lui
 * qui est censé garantir l'enrôlement. Toute la sécurité repose sur la comparaison humaine du
 * code d'appairage, recalculé par l'appareil approbateur. Le serveur ne fait que stocker et
 * transmettre le certificat pour que les clients puissent le vérifier entre eux.
 */
final class DeviceService
{
    private const string DEVICE_ID_PATTERN = '/\A[0-9a-f]{32}\z/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DeviceRepository $devices,
        private readonly MembershipService $membership,
        private readonly RealtimePublisher $realtime,
        private readonly ClockInterface $clock,
    ) {
    }

    public function assertValidDeviceId(string $deviceId): void
    {
        if (1 !== preg_match(self::DEVICE_ID_PATTERN, $deviceId)) {
            throw new InvalidPayloadException('Identifiant d\'appareil invalide : 32 caractères hexadécimaux attendus.');
        }
    }

    /**
     * Enregistre un appareil en attente d'approbation, et prévient les appareils déjà actifs
     * du même utilisateur pour qu'ils affichent le code d'appairage à comparer.
     */
    public function register(User $user, string $deviceId, string $ikPub, string $skPub, string $label): Device
    {
        $device = $this->createDevice($user, $deviceId, $ikPub, $skPub, $label);
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        foreach ($this->devices->findActiveByUser($user) as $active) {
            $this->realtime->publishDevicePending($device, $active);
        }

        return $device;
    }

    /**
     * Premier appareil d'un compte : aucun approbateur possible, l'attestation vient du jeton
     * d'invitation (confiance à la première utilisation, PROTOCOL.md §5).
     */
    public function registerBootstrapDevice(User $user, string $deviceId, string $ikPub, string $skPub, string $label): Device
    {
        $device = $this->createDevice($user, $deviceId, $ikPub, $skPub, $label);
        $device->approve(null, null, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        return $device;
    }

    public function approve(Device $target, Device $approver, ?string $approvalCert): void
    {
        if (!$approver->isActive()) {
            throw new ForbiddenException('Seul un appareil actif peut en approuver un autre.');
        }

        if (!$approver->getUser()->getId()->equals($target->getUser()->getId())) {
            throw new ForbiddenException('Un appareil ne peut approuver que les appareils de son propre compte.');
        }

        if (DeviceStatus::Pending !== $target->getStatus()) {
            throw new ConflictException('Cet appareil n\'est pas en attente d\'approbation.');
        }

        $target->approve($approvalCert, $approver, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->entityManager->flush();

        $this->membership->refreshMemberSetForUser($target->getUser());
    }

    /**
     * Révocation demandée par un appareil précis.
     *
     * Un appareil `pending` n'a jamais été confronté à un humain : son code d'appairage n'a
     * pas été comparé. Le laisser révoquer les appareils ACTIFS du compte permettrait, depuis
     * une simple session pseudo + PIN, de briquer le compte — et d'en évincer le seul appareil
     * capable d'approuver quoi que ce soit. Il ne peut donc que se retirer lui-même.
     */
    public function revokeAs(Device $target, Device $actor): void
    {
        if (!$target->getUser()->getId()->equals($actor->getUser()->getId())) {
            throw new ForbiddenException('Vous ne pouvez révoquer que vos propres appareils.');
        }

        if (DeviceStatus::Pending === $actor->getStatus() && $actor->getDeviceId() !== $target->getDeviceId()) {
            throw new ForbiddenException('Un appareil en attente d\'approbation ne peut révoquer que lui-même.');
        }

        $this->revoke($target);
    }

    public function revoke(Device $device): void
    {
        if (DeviceStatus::Revoked === $device->getStatus()) {
            return;
        }

        $device->revoke();
        $this->entityManager->flush();

        $this->membership->refreshMemberSetForUser($device->getUser());
    }

    private function createDevice(User $user, string $deviceId, string $ikPub, string $skPub, string $label): Device
    {
        $this->assertValidDeviceId($deviceId);

        if (Device::PUBLIC_KEY_BYTES !== \strlen($ikPub) || Device::PUBLIC_KEY_BYTES !== \strlen($skPub)) {
            throw new InvalidPayloadException('Les clés publiques doivent faire 32 octets.');
        }

        if (null !== $this->devices->findOneByDeviceId($deviceId)) {
            throw new ConflictException('Cet appareil est déjà enregistré.');
        }

        return new Device(
            $user,
            $deviceId,
            $ikPub,
            $skPub,
            '' === $label ? 'Appareil' : mb_substr($label, 0, 64),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }
}
