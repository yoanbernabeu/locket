<?php

declare(strict_types=1);

namespace App\Enum;

enum DeviceStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
}
