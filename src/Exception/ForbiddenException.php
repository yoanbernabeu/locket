<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Règle métier interdisant l'action à un utilisateur pourtant authentifié → 403.
 */
class ForbiddenException extends \RuntimeException
{
}
