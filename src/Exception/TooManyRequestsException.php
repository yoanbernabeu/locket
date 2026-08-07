<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Cadence ou quota dépassé → 429.
 */
class TooManyRequestsException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds = 60)
    {
        parent::__construct($message);
    }

    public function getRetryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }
}
