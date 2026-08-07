<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeExpiredData;
use App\Service\Retention\RetentionPurger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeExpiredDataHandler
{
    public function __construct(private readonly RetentionPurger $purger)
    {
    }

    public function __invoke(PurgeExpiredData $message): void
    {
        $this->purger->purge();
    }
}
