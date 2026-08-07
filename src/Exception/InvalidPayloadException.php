<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Requête syntaxiquement recevable mais dont le contenu est invalide → 400.
 */
class InvalidPayloadException extends \InvalidArgumentException
{
}
