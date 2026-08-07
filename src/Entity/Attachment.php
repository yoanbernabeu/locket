<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AttachmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AttachmentRepository::class)]
#[ORM\Table(name: 'attachment')]
#[ORM\UniqueConstraint(name: 'uniq_attachment_media_id', columns: ['media_id'])]
#[ORM\Index(name: 'idx_attachment_expires_at', columns: ['expires_at'])]
class Attachment
{
    public const int MEDIA_ID_BYTES = 16;
    public const int MAX_BYTES = 25 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    /** 32 caractères hexadécimaux : c'est aussi la capacité d'accès au blob. */
    #[ORM\Column(length: 32)]
    private string $mediaId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 255)]
    private string $path;

    #[ORM\Column(type: 'bigint')]
    private int $bytes;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private bool $linked = false;

    /**
     * Absent de API.md §1 : sans lui, l'exigence « seul un membre d'une conversation où le
     * média est référencé peut le lire » est inapplicable, puisque le `mediaId` vit dans le
     * clair chiffré du message et reste donc invisible au serveur. Renseigné par le client
     * au moment de l'envoi du message (champ optionnel `mediaIds`).
     */
    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Conversation $conversation = null;

    public function __construct(
        string $mediaId,
        User $owner,
        string $path,
        int $bytes,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->mediaId = $mediaId;
        $this->owner = $owner;
        $this->path = $path;
        $this->bytes = $bytes;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMediaId(): string
    {
        return $this->mediaId;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getBytes(): int
    {
        return $this->bytes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isLinked(): bool
    {
        return $this->linked;
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function linkTo(Conversation $conversation, \DateTimeImmutable $expiresAt): void
    {
        $this->linked = true;
        $this->conversation = $conversation;
        $this->expiresAt = $expiresAt;
    }
}
