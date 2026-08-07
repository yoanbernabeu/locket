<?php

declare(strict_types=1);

namespace App\Enum;

enum ConversationType: string
{
    /** Le groupe global, unique par instance : tout membre actif y appartient. */
    case Group = 'group';

    case Direct = 'direct';
}
