<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeviceStatus;
use App\Repository\DeviceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'device')]
#[ORM\UniqueConstraint(name: 'uniq_device_device_id', columns: ['device_id'])]
#[ORM\Index(name: 'idx_device_user_status', columns: ['user_id', 'status'])]
class Device
{
    public const int ID_BYTES = 16;
    public const int PUBLIC_KEY_BYTES = 32;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Hexadécimal de 16 octets, dérivé côté client de SHA-256(IKpub ‖ SKpub). */
    #[ORM\Column(length: 32)]
    private string $deviceId;

    #[ORM\Column(type: 'binary_string', length: 32)]
    private string $ikPub;

    #[ORM\Column(type: 'binary_string', length: 32)]
    private string $skPub;

    #[ORM\Column(length: 16, enumType: DeviceStatus::class)]
    private DeviceStatus $status = DeviceStatus::Pending;

    /** Signature Ed25519 produite par l'appareil approbateur ; le serveur ne la vérifie pas. */
    #[ORM\Column(type: 'binary_string', nullable: true)]
    private ?string $approvalCert = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $approvedBy = null;

    #[ORM\Column(length: 64)]
    private string $label;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    public function __construct(
        User $user,
        string $deviceId,
        string $ikPub,
        string $skPub,
        string $label,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->deviceId = $deviceId;
        $this->ikPub = $ikPub;
        $this->skPub = $skPub;
        $this->label = $label;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDeviceId(): string
    {
        return $this->deviceId;
    }

    public function getIkPub(): string
    {
        return $this->ikPub;
    }

    public function getSkPub(): string
    {
        return $this->skPub;
    }

    public function getStatus(): DeviceStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return DeviceStatus::Active === $this->status;
    }

    public function getApprovalCert(): ?string
    {
        return $this->approvalCert;
    }

    public function getApprovedBy(): ?self
    {
        return $this->approvedBy;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function approve(?string $approvalCert, ?self $approvedBy, \DateTimeImmutable $at): void
    {
        $this->status = DeviceStatus::Active;
        $this->approvalCert = $approvalCert;
        $this->approvedBy = $approvedBy;
        $this->approvedAt = $at;
    }

    public function revoke(): void
    {
        $this->status = DeviceStatus::Revoked;
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }
}
