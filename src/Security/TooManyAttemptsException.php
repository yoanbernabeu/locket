<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Tentatives de connexion trop nombreuses → 429.
 */
final class TooManyAttemptsException extends AuthenticationException
{
    private int $retryAfterSeconds = 60;

    public function setRetryAfterSeconds(int $seconds): void
    {
        $this->retryAfterSeconds = max(1, $seconds);
    }

    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }

    public function getMessageKey(): string
    {
        return 'Trop de tentatives. Réessayez dans quelques instants.';
    }
}
