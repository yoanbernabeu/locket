<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Conversation;
use App\Repository\ConversationMemberRepository;
use App\Repository\MessageRepository;

final class ConversationPresenter
{
    public function __construct(
        private readonly ConversationMemberRepository $members,
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * @return array{id: string, type: string, title: string|null, currentEpoch: int, memberSetVersion: int, members: list<array{id: string, pseudo: string, displayName: string}>, lastSeq: int}
     */
    public function present(Conversation $conversation): array
    {
        $members = [];

        foreach ($this->members->findActiveMembers($conversation) as $membership) {
            $user = $membership->getUser();
            $members[] = [
                'id' => $user->getId()->toRfc4122(),
                'pseudo' => $user->getPseudo(),
                'displayName' => $user->getDisplayName(),
            ];
        }

        return [
            'id' => $conversation->getId()->toRfc4122(),
            'type' => $conversation->getType()->value,
            'title' => $conversation->getTitle(),
            'currentEpoch' => $conversation->getCurrentEpoch(),
            'memberSetVersion' => $conversation->getMemberSetVersion(),
            'members' => $members,
            'lastSeq' => $this->messages->lastSeq($conversation),
        ];
    }

    /**
     * @param list<Conversation> $conversations
     *
     * @return list<array<string, mixed>>
     */
    public function presentAll(array $conversations): array
    {
        return array_map($this->present(...), $conversations);
    }
}
