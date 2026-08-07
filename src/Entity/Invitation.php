<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvitationPurpose;
use App\Repository\InvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Le serveur ne connaît que l'empreinte de l'invitation : une fuite de la base ne livre
 * aucun lien d'invitation exploitable (PROTOCOL.md §6).
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\Table(name: 'invitation')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_invite_id_hash', columns: ['invite_id_hash'])]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    private string $inviteIdHash;

    #[ORM\Column(length: 64)]
    private string $pseudo;

    #[ORM\Column(length: 64)]
    private string $displayName;

    #[ORM\Column(length: 16, enumType: InvitationPurpose::class)]
    private InvitationPurpose $purpose;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $targetUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(
        string $inviteIdHash,
        string $pseudo,
        string $displayName,
        InvitationPurpose $purpose,
        User $createdBy,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
        ?User $targetUser = null,
    ) {
        $this->id = Uuid::v7();
        $this->inviteIdHash = $inviteIdHash;
        $this->pseudo = $pseudo;
        $this->displayName = $displayName;
        $this->purpose = $purpose;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
        $this->targetUser = $targetUser;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInviteIdHash(): string
    {
        return $this->inviteIdHash;
    }

    public function getPseudo(): string
    {
        return $this->pseudo;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getPurpose(): InvitationPurpose
    {
        return $this->purpose;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(\DateTimeImmutable $at): void
    {
        $this->revokedAt = $at;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && null === $this->revokedAt && $this->expiresAt > $now;
    }
}
