<?php

declare(strict_types=1);

namespace App\Security\Attribute;

use App\Enum\DeviceStatus;

/**
 * Ouvre un endpoint aux appareils qui ne sont pas (encore) actifs.
 *
 * Par défaut, toute action d'API authentifiée exige un appareil `active`. Seuls les endpoints
 * d'enrôlement et d'état portent cet attribut : c'est ce qui empêche un appareil `pending`,
 * dont le code d'appairage n'a jamais été comparé par un humain, de lire quoi que ce soit.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class AllowDeviceStatus
{
    /** @var list<DeviceStatus> */
    public array $statuses;

    public function __construct(DeviceStatus ...$statuses)
    {
        $this->statuses = array_values($statuses);
    }
}
