<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationMemberRepository::class)]
#[ORM\Table(name: 'conversation_member')]
#[ORM\UniqueConstraint(name: 'uniq_member_conversation_user', columns: ['conversation_id', 'user_id'])]
class ConversationMember
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $joinedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leftAt = null;

    public function __construct(Conversation $conversation, User $user, \DateTimeImmutable $joinedAt)
    {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->user = $user;
        $this->joinedAt = $joinedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getLeftAt(): ?\DateTimeImmutable
    {
        return $this->leftAt;
    }

    public function isActive(): bool
    {
        return null === $this->leftAt;
    }

    public function leave(\DateTimeImmutable $at): void
    {
        $this->leftAt = $at;
    }

    public function rejoin(\DateTimeImmutable $at): void
    {
        $this->leftAt = null;
        $this->joinedAt = $at;
    }
}
