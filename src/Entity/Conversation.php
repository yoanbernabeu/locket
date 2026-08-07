<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConversationType;
use App\Repository\ConversationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversation')]
#[ORM\UniqueConstraint(name: 'uniq_conversation_direct_key', columns: ['direct_key'])]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: ConversationType::class)]
    private ConversationType $type;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $title;

    #[ORM\Column]
    private int $currentEpoch = 0;

    /**
     * Incrémenté à CHAQUE changement de composition (membre ou appareil). C'est cette valeur
     * qui devient le numéro d'époque lors de la rotation (PROTOCOL.md §4) : elle ne doit donc
     * jamais reculer ni stagner après un changement, sous peine de collision d'époques.
     */
    #[ORM\Column]
    private int $memberSetVersion = 0;

    /** `min(userA,userB):max(userA,userB)` — rend un 1-1 structurellement unique. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $directKey = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        ConversationType $type,
        \DateTimeImmutable $createdAt,
        ?string $title = null,
        ?string $directKey = null,
    ) {
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->createdAt = $createdAt;
        $this->title = $title;
        $this->directKey = $directKey;
    }

    public static function directKeyFor(Uuid $a, Uuid $b): string
    {
        $left = $a->toRfc4122();
        $right = $b->toRfc4122();

        return $left < $right ? $left.':'.$right : $right.':'.$left;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): ConversationType
    {
        return $this->type;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getCurrentEpoch(): int
    {
        return $this->currentEpoch;
    }

    public function setCurrentEpoch(int $epoch): void
    {
        $this->currentEpoch = $epoch;
    }

    public function getMemberSetVersion(): int
    {
        return $this->memberSetVersion;
    }

    public function bumpMemberSetVersion(): int
    {
        return ++$this->memberSetVersion;
    }

    public function getDirectKey(): ?string
    {
        return $this->directKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
