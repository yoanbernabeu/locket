<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Device;
use App\Util\Base64Url;

final class DevicePresenter
{
    /**
     * Le certificat d'approbation et les éléments qui permettent de le vérifier sont exposés
     * ensemble : sans `approverSkPub`, le client ne peut pas contrôler la signature, et sans
     * `approvedByDeviceId` il ne sait pas quel appareil du roster l'a produite. Le serveur ne
     * vérifie rien (DeviceService) — c'est justement pourquoi il doit tout transmettre.
     *
     * @return array{deviceId: string, label: string, status: string, ikPub: string, skPub: string, approvalCert: string|null, approvedByDeviceId: string|null, approverSkPub: string|null, createdAt: string, approvedAt: string|null, lastSeenAt: string|null}
     */
    public function present(Device $device): array
    {
        $certificate = $device->getApprovalCert();
        $approver = $device->getApprovedBy();

        return [
            'deviceId' => $device->getDeviceId(),
            'label' => $device->getLabel(),
            'status' => $device->getStatus()->value,
            'ikPub' => Base64Url::encode($device->getIkPub()),
            'skPub' => Base64Url::encode($device->getSkPub()),
            'approvalCert' => null === $certificate ? null : Base64Url::encode($certificate),
            'approvedByDeviceId' => $approver?->getDeviceId(),
            'approverSkPub' => null === $approver ? null : Base64Url::encode($approver->getSkPub()),
            'createdAt' => $device->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'approvedAt' => $device->getApprovedAt()?->format(\DateTimeInterface::ATOM),
            'lastSeenAt' => $device->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Device> $devices
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $devices): array
    {
        return array_map($this->present(...), $devices);
    }
}
