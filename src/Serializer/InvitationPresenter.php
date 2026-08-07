<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Invitation;

final class InvitationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Invitation $invitation): array
    {
        return [
            'id' => $invitation->getId()->toRfc4122(),
            'inviteIdHash' => $invitation->getInviteIdHash(),
            'pseudo' => $invitation->getPseudo(),
            'displayName' => $invitation->getDisplayName(),
            'purpose' => $invitation->getPurpose()->value,
            'targetUserId' => $invitation->getTargetUser()?->getId()->toRfc4122(),
            'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            'usedAt' => $invitation->getUsedAt()?->format(\DateTimeInterface::ATOM),
            'revokedAt' => $invitation->getRevokedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<Invitation> $invitations
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $invitations): array
    {
        return array_map($this->present(...), $invitations);
    }
}
