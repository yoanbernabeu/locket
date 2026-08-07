<?php

declare(strict_types=1);

namespace App\Enum;

enum InvitationPurpose: string
{
    case NewMember = 'new_member';

    /** Réenrôlement d'un membre existant ayant perdu tous ses appareils. */
    case Recovery = 'recovery';
}
