<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InstanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Singleton : une base Locket décrit une et une seule famille.
 */
#[ORM\Entity(repositoryClass: InstanceRepository::class)]
#[ORM\Table(name: 'instance')]
class Instance
{
    public const int SINGLETON_ID = 1;

    /**
     * `smallint` et non `integer` : sous SQLite, une clé primaire INTEGER mono-colonne est un
     * alias de `rowid` et l'introspection la rapporte comme auto-incrémentée, ce qui met
     * `doctrine:schema:validate` en désaccord permanent avec le mapping. L'identifiant vaut
     * toujours 1 : deux octets suffisent.
     */
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $installedAt = null;

    /**
     * Repli des clés VAPID lorsque l'environnement n'en fournit pas (voir VapidKeyProvider).
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vapidPublicKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vapidPrivateKey = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getInstalledAt(): ?\DateTimeImmutable
    {
        return $this->installedAt;
    }

    public function markInstalled(\DateTimeImmutable $at): void
    {
        $this->installedAt = $at;
    }

    public function isInstalled(): bool
    {
        return null !== $this->installedAt;
    }

    public function getVapidPublicKey(): ?string
    {
        return $this->vapidPublicKey;
    }

    public function getVapidPrivateKey(): ?string
    {
        return $this->vapidPrivateKey;
    }

    public function setVapidKeys(string $publicKey, string $privateKey): void
    {
        $this->vapidPublicKey = $publicKey;
        $this->vapidPrivateKey = $privateKey;
    }
}
