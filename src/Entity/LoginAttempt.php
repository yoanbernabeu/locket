<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LoginAttemptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Compteur d'échecs de connexion indexé par PSEUDO, et non par compte.
 *
 * POURQUOI une table à part plutôt que les colonnes de `user` : tant que le verrouillage vit
 * sur la ligne du membre, il ne peut exister que pour un pseudo existant. Un attaquant obtient
 * alors un oracle parfait — trois 401 puis un 429 signifient « ce compte existe », un 401
 * indéfiniment signifie « il n'existe pas ». Ici, la ligne est créée pour n'importe quel
 * pseudo présenté : les deux cas produisent exactement la même séquence de codes HTTP.
 *
 * La table est bornée par la purge de rétention, qui supprime les lignes inactives.
 */
#[ORM\Entity(repositoryClass: LoginAttemptRepository::class)]
#[ORM\Table(name: 'login_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_login_attempt_pseudo', columns: ['pseudo'])]
#[ORM\Index(name: 'idx_login_attempt_last_attempt_at', columns: ['last_attempt_at'])]
class LoginAttempt
{
    /** Le pseudo est normalisé puis tronqué : un pseudo légitime tient dans 64 caractères. */
    public const int PSEUDO_MAX_LENGTH = 64;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64)]
    private string $pseudo;

    #[ORM\Column]
    private int $failedAttempts = 0;

    #[ORM\Column]
    private \DateTimeImmutable $lastAttemptAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lockedUntil = null;

    public function __construct(string $pseudo, \DateTimeImmutable $at)
    {
        $this->id = Uuid::v7();
        $this->pseudo = $pseudo;
        $this->lastAttemptAt = $at;
    }

    /**
     * Clé de comptage : minuscules et découpe, pour que « Papa », « papa » et « papa   »
     * partagent le même compteur — sinon la limite se contourne par la casse.
     */
    public static function normalize(string $pseudo): string
    {
        return mb_substr(mb_strtolower(trim($pseudo)), 0, self::PSEUDO_MAX_LENGTH);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPseudo(): string
    {
        return $this->pseudo;
    }

    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    public function getLastAttemptAt(): \DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }

    public function isLockedAt(\DateTimeImmutable $now): bool
    {
        return null !== $this->lockedUntil && $this->lockedUntil > $now;
    }

    /**
     * @param int $failedAttempts compteur déjà incrémenté par l'appelant
     */
    public function registerFailure(int $failedAttempts, ?\DateTimeImmutable $lockedUntil, \DateTimeImmutable $at): void
    {
        $this->failedAttempts = $failedAttempts;
        $this->lockedUntil = $lockedUntil;
        $this->lastAttemptAt = $at;
    }

    public function reset(\DateTimeImmutable $at): void
    {
        $this->failedAttempts = 0;
        $this->lockedUntil = null;
        $this->lastAttemptAt = $at;
    }
}
