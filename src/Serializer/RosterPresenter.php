<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Conversation;
use App\Repository\DeviceRepository;
use App\Util\Base64Url;

/**
 * Représentation du roster d'une conversation (API.md §2).
 * Partagée entre l'endpoint `GET .../roster` et la réponse 409 d'un dépôt de clés, afin que
 * le client se resynchronise avec exactement la même vue dans les deux cas.
 */
final class RosterPresenter
{
    public function __construct(private readonly DeviceRepository $devices)
    {
    }

    /**
     * Chaque entrée porte de quoi vérifier l'attestation d'enrôlement de l'appareil
     * (PROTOCOL.md §5) : le certificat, l'appareil approbateur et sa clé de signature. Sans
     * ces trois champs, le client ne peut pas écarter du roster un appareil non vérifiable —
     * or c'est exactement ce que le modèle de sécurité lui demande de faire, puisque le
     * serveur est rangé parmi les adversaires.
     *
     * @return array{memberSetVersion: int, currentEpoch: int, devices: list<array{deviceId: string, ikPub: string, skPub: string, userId: string, pseudo: string, approvalCert: string|null, approvedByDeviceId: string|null, approverSkPub: string|null}>}
     */
    public function present(Conversation $conversation): array
    {
        $devices = [];

        foreach ($this->devices->findActiveDevicesForConversation($conversation) as $device) {
            $certificate = $device->getApprovalCert();
            $approver = $device->getApprovedBy();

            $devices[] = [
                'deviceId' => $device->getDeviceId(),
                'ikPub' => Base64Url::encode($device->getIkPub()),
                'skPub' => Base64Url::encode($device->getSkPub()),
                'userId' => $device->getUser()->getId()->toRfc4122(),
                'pseudo' => $device->getUser()->getPseudo(),
                'approvalCert' => null === $certificate ? null : Base64Url::encode($certificate),
                'approvedByDeviceId' => $approver?->getDeviceId(),
                'approverSkPub' => null === $approver ? null : Base64Url::encode($approver->getSkPub()),
            ];
        }

        return [
            'memberSetVersion' => $conversation->getMemberSetVersion(),
            'currentEpoch' => $conversation->getCurrentEpoch(),
            'devices' => $devices,
        ];
    }
}
