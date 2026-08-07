<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_pseudo', columns: ['pseudo'])]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['pseudo'], message: 'Ce pseudo est déjà pris.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $pseudo;

    #[ORM\Column(length: 64)]
    private string $displayName;

    /** Argon2id — le PIN ne dérive aucune clé, il n'authentifie que la session. */
    #[ORM\Column(length: 255)]
    private string $pinHash;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private bool $isAdmin = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $disabledAt = null;

    #[ORM\Column]
    private int $failedAttempts = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    public function __construct(
        string $pseudo,
        string $displayName,
        string $pinHash,
        \DateTimeImmutable $createdAt,
        bool $isAdmin = false,
        ?string $email = null,
    ) {
        $this->id = Uuid::v7();
        $this->pseudo = $pseudo;
        $this->displayName = $displayName;
        $this->pinHash = $pinHash;
        $this->createdAt = $createdAt;
        $this->isAdmin = $isAdmin;
        $this->email = $email;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPseudo(): string
    {
        return $this->pseudo;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getPinHash(): string
    {
        return $this->pinHash;
    }

    public function setPinHash(string $pinHash): void
    {
        $this->pinHash = $pinHash;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function setAdmin(bool $isAdmin): void
    {
        $this->isAdmin = $isAdmin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDisabledAt(): ?\DateTimeImmutable
    {
        return $this->disabledAt;
    }

    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }

    public function disable(\DateTimeImmutable $at): void
    {
        $this->disabledAt = $at;
    }

    public function enable(): void
    {
        $this->disabledAt = null;
    }

    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    /**
     * Reflet du compteur tenu par App\Entity\LoginAttempt, qui fait autorité parce qu'il
     * existe même pour un pseudo inconnu. Ces deux colonnes ne servent plus qu'à rendre
     * l'état visible en administration.
     */
    public function applyFailureState(int $failedAttempts, ?\DateTimeImmutable $lockedUntil): void
    {
        $this->failedAttempts = max(0, $failedAttempts);
        $this->lockedUntil = $lockedUntil;
    }

    public function resetFailedAttempts(): void
    {
        $this->failedAttempts = 0;
        $this->lockedUntil = null;
    }

    public function isLockedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > $now;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->isAdmin ? ['ROLE_USER', 'ROLE_ADMIN'] : ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->pinHash;
    }

    public function getUserIdentifier(): string
    {
        return $this->pseudo;
    }
}
