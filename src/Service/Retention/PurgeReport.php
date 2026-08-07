<?php

declare(strict_types=1);

namespace App\Service\Retention;

final readonly class PurgeReport
{
    public function __construct(
        public int $messagesDeleted = 0,
        public int $attachmentsDeleted = 0,
        public int $mediaFilesDeleted = 0,
        public int $conversationKeysDeleted = 0,
        public int $invitationsDeleted = 0,
        public int $loginAttemptsDeleted = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        return 0 === $this->messagesDeleted
            && 0 === $this->attachmentsDeleted
            && 0 === $this->mediaFilesDeleted
            && 0 === $this->conversationKeysDeleted
            && 0 === $this->invitationsDeleted
            && 0 === $this->loginAttemptsDeleted;
    }
}
