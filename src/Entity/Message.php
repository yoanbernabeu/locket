<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un message est un blob opaque. Le serveur n'en lit que l'en-tête (50 octets) pour le router.
 *
 * CHOIX D'IDENTITÉ : `serverSeq` est la clé primaire auto-incrémentée, `id` reste l'UUID exposé
 * par l'API. SQLite n'admet qu'une seule colonne AUTOINCREMENT par table, et il faut que ce
 * soit celle-là : la synchronisation incrémentale (`?since=<seq>`) casserait si un numéro était
 * réattribué après une purge. Le mot-clé AUTOINCREMENT interdit précisément cette réutilisation.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
#[ORM\UniqueConstraint(name: 'uniq_message_id', columns: ['id'])]
#[ORM\Index(name: 'idx_message_conversation_seq', columns: ['conversation_id', 'server_seq'])]
#[ORM\Index(name: 'idx_message_conversation_epoch', columns: ['conversation_id', 'key_epoch'])]
#[ORM\Index(name: 'idx_message_expires_at', columns: ['expires_at'])]
class Message
{
    public const int HEADER_BYTES = 50;
    public const int MAX_ENVELOPE_BYTES = 65536;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $serverSeq = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $senderUser;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $senderDevice;

    #[ORM\Column(type: 'smallint')]
    private int $msgType;

    /**
     * Copié depuis l'en-tête LK1M. Redondant avec l'enveloppe, mais indispensable en SQL : la
     * purge doit savoir si une époque a encore des messages vivants avant d'en supprimer la
     * clé, et elle ne peut pas décoder 4 octets au milieu d'un BLOB.
     */
    #[ORM\Column]
    private int $keyEpoch;

    #[ORM\Column(type: 'binary_string')]
    private string $envelope;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(
        Conversation $conversation,
        User $senderUser,
        Device $senderDevice,
        int $msgType,
        int $keyEpoch,
        string $envelope,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ) {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->senderUser = $senderUser;
        $this->senderDevice = $senderDevice;
        $this->msgType = $msgType;
        $this->keyEpoch = $keyEpoch;
        $this->envelope = $envelope;
        $this->createdAt = $createdAt;
        $this->expiresAt = $expiresAt;
    }

    public function getServerSeq(): int
    {
        return $this->serverSeq ?? throw new \LogicException('Le numéro de séquence n\'est attribué qu\'après persistance.');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getSenderUser(): User
    {
        return $this->senderUser;
    }

    public function getSenderDevice(): Device
    {
        return $this->senderDevice;
    }

    public function getMsgType(): int
    {
        return $this->msgType;
    }

    public function getKeyEpoch(): int
    {
        return $this->keyEpoch;
    }

    public function getEnvelope(): string
    {
        return $this->envelope;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
