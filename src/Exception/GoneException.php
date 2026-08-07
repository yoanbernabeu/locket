<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Ressource expirée ou hors gabarit → 410 (code imposé par API.md, y compris pour un média
 * dépassant la taille maximale).
 */
class GoneException extends \RuntimeException
{
}
