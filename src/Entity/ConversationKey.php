<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConversationKeyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une enveloppe LK1W : la clé de conversation d'une époque, chiffrée pour un appareil précis.
 * Le serveur ne peut pas l'ouvrir — il ne fait que la distribuer.
 */
#[ORM\Entity(repositoryClass: ConversationKeyRepository::class)]
#[ORM\Table(name: 'conversation_key')]
#[ORM\UniqueConstraint(name: 'uniq_key_conversation_epoch_device', columns: ['conversation_id', 'epoch', 'recipient_device_id'])]
#[ORM\Index(name: 'idx_key_device_conversation', columns: ['recipient_device_id', 'conversation_id'])]
class ConversationKey
{
    public const int WRAP_BYTES = 130;
    public const int ATTESTATION_BYTES = 138;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column]
    private int $epoch;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'recipient_device_id', nullable: false, onDelete: 'CASCADE')]
    private Device $recipientDevice;

    #[ORM\Column(type: 'binary_string', length: 130)]
    private string $wrap;

    /**
     * Attestation LK1R de la rotation qui a produit cette clé (PROTOCOL.md §3 bis).
     *
     * Sans elle, une enveloppe de clé ne prouve rien : la fabriquer ne demande que la
     * clé publique du destinataire, que ce serveur détient. Il pourrait donc distribuer
     * sa propre clé de conversation et lire tout le trafic suivant. Le serveur la stocke
     * et la restitue sans jamais la vérifier — il n'a pas les clés privées pour cela ;
     * ce sont les clients qui la vérifient entre eux.
     *
     * Identique pour toutes les lignes d'une même époque : c'est le bundle qu'elle
     * couvre, pas l'enveloppe individuelle.
     */
    #[ORM\Column(type: 'binary_string', length: 138)]
    private string $attestation;

    /**
     * Absent de API.md §1 : indispensable pour appliquer la rétention à 7 jours des clés
     * anciennes décrite dans PROTOCOL.md §4 sans deviner leur âge.
     */
    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Conversation $conversation,
        int $epoch,
        Device $recipientDevice,
        string $wrap,
        string $attestation,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->epoch = $epoch;
        $this->recipientDevice = $recipientDevice;
        $this->wrap = $wrap;
        $this->attestation = $attestation;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getEpoch(): int
    {
        return $this->epoch;
    }

    public function getRecipientDevice(): Device
    {
        return $this->recipientDevice;
    }

    public function getAttestation(): string
    {
        return $this->attestation;
    }

    public function getWrap(): string
    {
        return $this->wrap;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
